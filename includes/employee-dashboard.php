<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';


$user = require_roles(['employee']);

$employeeView = $employeeView ?? 'dashboard';


$viewRoutes = [

    'daily' =>
        'employee-daily-work.php',

    'history' =>
        'employee-work-history.php',

    'assigned' =>
        'employee-assigned-work.php'

];


$viewRoute =
    $viewRoutes[$employeeView]
    ??
    dashboard_path($user);


/* =========================================================
   SECURE ASSIGNMENT FILE VIEW / DOWNLOAD
========================================================= */

if (!empty($_GET['task_file'])) {
    $fileId = positive_id($_GET['task_file']);
    $requestedMode = (($_GET['mode'] ?? 'view') === 'download') ? 'download' : 'view';

    $attachment = one(
        "SELECT f.*, a.employee_id
         FROM work_assignment_attachments f
         JOIN work_assignments a ON a.id=f.assignment_id
         WHERE f.id=? AND a.employee_id=? AND a.deleted_at IS NULL",
        [$fileId, $user['id']]
    );

    if (!$attachment) {
        fail(404, 'Attachment not found.');
    }

    $relativePath = ltrim((string)($attachment['file_path'] ?? ''), "/\\");
    if ($relativePath === '') {
        fail(404, 'Attachment path is missing.');
    }

    $baseDirectory = realpath(__DIR__ . '/uploads/work-assignments');
    $absolutePath = realpath(
        __DIR__ . DIRECTORY_SEPARATOR .
        str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath)
    );

    if (!$baseDirectory || !$absolutePath || !is_file($absolutePath) || !is_readable($absolutePath)) {
        fail(404, 'Attachment file is missing from storage.');
    }

    $allowedBase = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($absolutePath, $allowedBase, strlen($allowedBase)) !== 0) {
        fail(403, 'Invalid attachment path.');
    }

    $mimeType = trim((string)($attachment['mime_type'] ?? ''));
    if ($mimeType === '' || $mimeType === 'application/octet-stream') {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($absolutePath);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }
        }
    }
    if ($mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    $originalName = trim((string)($attachment['original_name'] ?? 'attachment'));
    if ($originalName === '') {
        $originalName = 'attachment';
    }

    $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
    if (!is_string($fallbackName) || $fallbackName === '') {
        $fallbackName = 'attachment';
    }

    $lowerMime = strtolower($mimeType);
    $isImage = strpos($lowerMime, 'image/') === 0;
    $isVideo = strpos($lowerMime, 'video/') === 0;
    $isPdf = $lowerMime === 'application/pdf';
    $inlineAllowed = $isImage || $isVideo || $isPdf;
    $disposition = ($requestedMode === 'download' || !$inlineAllowed) ? 'attachment' : 'inline';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $fileSize = filesize($absolutePath);

    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mimeType);
    header(
        'Content-Disposition: ' . $disposition .
        '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName)
    );

    $start = 0;
    $end = max(0, $fileSize - 1);

    if ($isVideo && !empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        if ($matches[1] !== '') {
            $start = (int)$matches[1];
        }
        if ($matches[2] !== '') {
            $end = (int)$matches[2];
        }

        $start = max(0, $start);
        $end = min($fileSize - 1, $end);

        if ($start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    $handle = fopen($absolutePath, 'rb');
    if (!$handle) {
        fail(500, 'Unable to open attachment.');
    }

    if ($start > 0) {
        fseek($handle, $start);
    }

    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (function_exists('flush')) {
            flush();
        }
    }

    fclose($handle);
    exit;
}



if (
    isset($expectedDepartment)
    &&
    $user['department_code']
    !==
    $expectedDepartment
) {

    redirect(
        dashboard_path($user)
    );

}



/* Timer database columns already exist; schema changes are not run from this page. */

/* =========================================================
   EXACT TIME FORMAT
========================================================= */

function employee_assignment_duration(
    int $seconds
): string {


    $seconds =
        max(
            0,
            $seconds
        );


    $hours =
        intdiv(
            $seconds,
            3600
        );


    $minutes =
        intdiv(
            $seconds % 3600,
            60
        );


    $secs =
        $seconds % 60;


    return sprintf(
        '%02d:%02d:%02d',
        $hours,
        $minutes,
        $secs
    );

}



/* =========================================================
   ATTACHMENTS
========================================================= */

function employee_assignment_files(
    int $assignmentId
): array {


    try {


        return rows(
            "SELECT
                id,
                assignment_id,
                original_name,
                stored_name,
                file_path,
                mime_type,
                file_size,
                created_at

             FROM work_assignment_attachments

             WHERE assignment_id=?

             ORDER BY id",

            [
                $assignmentId
            ]
        );


    } catch (Throwable $e) {


        return [];

    }

}



/* =========================================================
   DATE
========================================================= */

try {


    $date =
        $employeeView === 'assigned'

            ? today()

            : date_input(
                'date',
                [
                    'date' =>
                        $_GET['date']
                        ?? today()
                ]
            );


} catch (InvalidArgumentException $e) {


    fail(
        422,
        $e->getMessage()
    );

}



/* =========================================================
   ASSIGNMENT DATE
========================================================= */

$assignmentDate = null;


if (
    $employeeView === 'assigned'
    &&
    isset(
        $_GET['assignment_date']
    )
) {


    try {


        $assignmentDate =
            date_input(
                'assignment_date',
                [
                    'assignment_date' =>
                        $_GET[
                            'assignment_date'
                        ]
                ]
            );


    } catch (
        InvalidArgumentException $e
    ) {


        fail(
            422,
            $e->getMessage()
        );

    }

}



$error = null;



/* =========================================================
   POST
========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    ===
    'POST'
) {


    check_csrf();


    try {


        $action =
            $_POST['action']
            ?? '';



        /* =====================================================
           START ASSIGNED TASK
        ===================================================== */

        if (
            $action ===
            'start_assignment'
        ) {


            $assignmentId =
                positive_id(
                    $_POST[
                        'assignment_id'
                    ]
                    ?? null
                );


            db()->beginTransaction();



            $assignment =
                one(
                    "SELECT *

                     FROM work_assignments

                     WHERE
                        id=?
                        AND employee_id=?
                        AND deleted_at IS NULL

                     FOR UPDATE",

                    [
                        $assignmentId,
                        $user['id']
                    ]
                );



            if (!$assignment) {


                fail(
                    403,
                    'You can only start your own assigned work.'
                );

            }



            if (
                !empty(
                    $assignment[
                        'completed_at'
                    ]
                )
            ) {


                throw new InvalidArgumentException(
                    'This task is already finished.'
                );

            }



            /*
             * Start time only first click lo save avvali.
             * Page refresh chesthe timer reset avvakudadhu.
             */

            if (
                empty(
                    $assignment[
                        'started_at'
                    ]
                )
            ) {


                query(
                    "UPDATE work_assignments

                     SET
                        started_at=NOW(),
                        completed_at=NULL,
                        time_spent_seconds=NULL,
                        time_spent_minutes=NULL,
                        status='pending'

                     WHERE id=?",

                    [
                        $assignmentId
                    ]
                );

            }



            db()->commit();



            flash(
                'Work started. Timer is running.'
            );



            redirect(
                'employee-assigned-work.php?assignment_date='
                .
                urlencode(
                    $assignment[
                        'work_date'
                    ]
                )
            );

        }



        /* =====================================================
           END ASSIGNED TASK
        ===================================================== */

        elseif (
            $action ===
            'end_assignment'
        ) {


            $assignmentId =
                positive_id(
                    $_POST[
                        'assignment_id'
                    ]
                    ?? null
                );



            $remark =
                trim(
                    (string) (
                        $_POST['remark']
                        ?? ''
                    )
                );



            if (
                mb_strlen(
                    $remark
                )
                >
                5000
            ) {


                throw new InvalidArgumentException(
                    'Remark must not exceed 5,000 characters.'
                );

            }



            db()->beginTransaction();



            $assignment =
                one(
                    "SELECT *

                     FROM work_assignments

                     WHERE
                        id=?
                        AND employee_id=?
                        AND deleted_at IS NULL

                     FOR UPDATE",

                    [
                        $assignmentId,
                        $user['id']
                    ]
                );



            if (!$assignment) {


                fail(
                    403,
                    'You can only finish your own assigned work.'
                );

            }



            if (
                empty(
                    $assignment[
                        'started_at'
                    ]
                )
            ) {


                throw new InvalidArgumentException(
                    'Click Start Work before ending this task.'
                );

            }



            if (
                !empty(
                    $assignment[
                        'completed_at'
                    ]
                )
            ) {


                throw new InvalidArgumentException(
                    'This task is already finished.'
                );

            }



            /* =================================================
               EXACT START → END TIME
            ================================================= */

            $startedTimestamp =
                strtotime(
                    $assignment[
                        'started_at'
                    ]
                );


            $completedTimestamp =
                time();



            $timeSpentSeconds =
                max(
                    0,
                    $completedTimestamp
                    -
                    $startedTimestamp
                );



            /*
             * Existing reports use minutes.
             */

            $timeSpentMinutes =
                max(
                    1,
                    (int) ceil(
                        $timeSpentSeconds
                        /
                        60
                    )
                );



            /* =================================================
               SAVE TIMER + STATUS + REMARK
            ================================================= */

            query(
                "UPDATE work_assignments

                 SET
                    completed_at=NOW(),
                    time_spent_seconds=?,
                    time_spent_minutes=?,
                    employee_remark=?,
                    status='completed'

                 WHERE id=?",

                [
                    $timeSpentSeconds,
                    $timeSpentMinutes,
                    $remark,
                    $assignmentId
                ]
            );



            /* =================================================
               CREATE EMPLOYEE WORK SHEET
            ================================================= */

            $sheet =
                work_sheet(
                    $user,
                    $assignment[
                        'work_date'
                    ]
                );



            /* =================================================
               EXISTING ENTRY
            ================================================= */

            $existingEntry =
                one(
                    "SELECT id

                     FROM daily_work_entries

                     WHERE
                        submission_id=?
                        AND assignment_id=?",

                    [
                        $sheet['id'],
                        $assignmentId
                    ]
                );


            $entryId =
                $existingEntry

                    ? (int)
                        $existingEntry['id']

                    : null;



            /* =================================================
               SAVE INTO DAILY WORK ENTRIES
            ================================================= */

            $common = [


                'client_id' =>

                    $assignment[
                        'client_id'
                    ]
                    ===
                    null

                        ? null

                        : (int)
                            $assignment[
                                'client_id'
                            ],


                'task_title' =>

                    $assignment[
                        'title'
                    ],


                'task_status' =>

                    'completed',


                'remark' =>

                    $remark,


                'assignment_id' =>

                    $assignmentId,


                'time_spent_minutes' =>

                    $timeSpentMinutes

            ];



            save_work_entry(

                $sheet,

                $sheet[
                    'department_code'
                ],

                $common,

                [],

                $entryId

            );



            /* =================================================
               SYNC ASSIGNMENT
            ================================================= */

            sync_assignment(
                $assignmentId
            );



            /* =================================================
               SUBMIT TO MANAGER
            ================================================= */

            submit_sheet(
                $sheet,
                false
            );



            /* =================================================
               ADMIN / MANAGER NOTIFICATION
            ================================================= */

            notify_staff(

                'work_submitted',

                $user['full_name']
                .
                ' finished an assigned task',

                $assignment[
                    'work_date'
                ]
                .
                ' · '
                .
                $assignment[
                    'title'
                ]
                .
                ' · Time '
                .
                employee_assignment_duration(
                    $timeSpentSeconds
                )
                .
                (
                    $remark !== ''

                        ? ' · ' . $remark

                        : ''
                ),

                (int)
                    $sheet['id']

            );



            db()->commit();



            flash(
                'Task finished and submitted successfully.'
            );



            redirect(
                'employee-assigned-work.php?assignment_date='
                .
                urlencode(
                    $assignment[
                        'work_date'
                    ]
                )
            );

        }



        /* =====================================================
           EXISTING SYSTEM
        ===================================================== */

        else {


            if (
                $date >
                today()
            ) {


                throw new InvalidArgumentException(
                    'You can submit work for today or an earlier date.'
                );

            }



            $title = '';

            $client = null;

            $assignmentId = null;



            db()->beginTransaction();



            /* =================================================
               EXISTING ASSIGNMENT UPDATE
               Compatibility
            ================================================= */

            if (
                $action ===
                'update_assignment'
            ) {


                $assignment =
                    one(
                        "SELECT *

                         FROM work_assignments

                         WHERE
                            id=?
                            AND employee_id=?
                            AND deleted_at IS NULL

                         FOR UPDATE",

                        [
                            positive_id(
                                $_POST[
                                    'assignment_id'
                                ]
                                ?? null
                            ),

                            $user[
                                'id'
                            ]
                        ]
                    );


                if (!$assignment) {


                    fail(
                        403,
                        'You can only update your own assigned work.'
                    );

                }


                $assignmentId =
                    (int)
                        $assignment[
                            'id'
                        ];


                $title =
                    $assignment[
                        'title'
                    ];


                $client =
                    $assignment[
                        'client_id'
                    ]
                    ===
                    null

                        ? null

                        : (int)
                            $assignment[
                                'client_id'
                            ];

            }



            /* =================================================
               NORMAL DAILY ENTRY
            ================================================= */

            elseif (
                $action ===
                'save_entry'
            ) {


                $title =
                    text_input(
                        'task_title',
                        255
                    );


                $client =
                    empty(
                        $_POST[
                            'client_id'
                        ]
                    )

                        ? null

                        : positive_id(
                            $_POST[
                                'client_id'
                            ]
                        );



                if (
                    $client !==
                        null
                    &&
                    !one(
                        "SELECT id

                         FROM clients

                         WHERE
                            id=?
                            AND is_active=1
                            AND deleted_at IS NULL",

                        [
                            $client
                        ]
                    )
                ) {


                    throw new InvalidArgumentException(
                        'Choose an active client.'
                    );

                }

            }



            elseif (
                !in_array(
                    $action,
                    [
                        'submit_day',
                        'save_tasks',
                        'save_social_tasks'
                    ],
                    true
                )
            ) {


                throw new InvalidArgumentException(
                    'Invalid action.'
                );

            }



            $sheet =
                work_sheet(
                    $user,
                    $date
                );



            /* =================================================
               SOCIAL MEDIA
            ================================================= */

            if (
                $action ===
                'save_social_tasks'
            ) {


                if (
                    $sheet[
                        'department_code'
                    ]
                    !==
                    'social_media'
                ) {


                    throw new InvalidArgumentException(
                        'This task form is only for Social Media employees.'
                    );

                }



                $tasks =
                    $_POST[
                        'tasks'
                    ]
                    ?? null;



                if (
                    !is_array(
                        $tasks
                    )
                    ||
                    !$tasks
                ) {


                    throw new InvalidArgumentException(
                        'Add at least one client task before submitting.'
                    );

                }



                if (
                    filter_var(
                        $_POST[
                            'task_count'
                        ]
                        ?? null,

                        FILTER_VALIDATE_INT
                    )
                    !==
                    count(
                        $tasks
                    )
                ) {


                    throw new InvalidArgumentException(
                        'The task form was incomplete. Please try again.'
                    );

                }



                $validated = [];



                foreach (
                    array_values(
                        $tasks
                    )
                    as $index => $task
                ) {


                    try {


                        if (
                            !is_array(
                                $task
                            )
                        ) {


                            throw new InvalidArgumentException(
                                'Enter valid task details.'
                            );

                        }


                        $validated[] =
                            social_media_task_data(
                                $task
                            );


                    } catch (
                        InvalidArgumentException $e
                    ) {


                        throw new InvalidArgumentException(
                            'Task '
                            .
                            ($index + 1)
                            .
                            ': '
                            .
                            $e->getMessage()
                        );

                    }

                }



                foreach (
                    $validated
                    as $task
                ) {


                    $count =
                        $task[
                            'item_count'
                        ];


                    unset(
                        $task[
                            'platforms'
                        ],
                        $task[
                            'item_count'
                        ]
                    );


                    $task[
                        'social_content_count'
                    ] =
                        $count;



                    $metrics = [


                        'social_platform' =>

                            $task[
                                'social_platform'
                            ],


                        'posts_count' =>

                            $task[
                                'work_type'
                            ]
                            ===
                            'poster'

                                ? $count

                                : 0,


                        'reels_count' =>

                            $task[
                                'work_type'
                            ]
                            ===
                            'reel'

                                ? $count

                                : 0,


                        'replies_count' =>
                            0

                    ];



                    save_work_entry(

                        $sheet,

                        'social_media',

                        $task,

                        $metrics

                    );

                }



                submit_sheet(
                    $sheet
                );

            }



            /* =================================================
               GRAPHIC / VIDEO TASKS
            ================================================= */

            elseif (
                $action ===
                'save_tasks'
            ) {


                if (
                    !creative_work_department(
                        $sheet[
                            'department_code'
                        ],
                        $sheet[
                            'department_name'
                        ]
                    )
                ) {


                    throw new InvalidArgumentException(
                        'This task form is for Graphic Designer and Video Editing departments.'
                    );

                }



                $tasks =
                    $_POST[
                        'tasks'
                    ]
                    ?? null;



                if (
                    !is_array(
                        $tasks
                    )
                    ||
                    !$tasks
                ) {


                    throw new InvalidArgumentException(
                        'Add at least one task before submitting.'
                    );

                }



                if (
                    filter_var(
                        $_POST[
                            'task_count'
                        ]
                        ?? null,

                        FILTER_VALIDATE_INT
                    )
                    !==
                    count(
                        $tasks
                    )
                ) {


                    throw new InvalidArgumentException(
                        'The task form was incomplete. Submit fewer tasks at a time and try again.'
                    );

                }



                $validated = [];



                foreach (
                    array_values(
                        $tasks
                    )
                    as $index => $task
                ) {


                    try {


                        if (
                            !is_array(
                                $task
                            )
                        ) {


                            throw new InvalidArgumentException(
                                'Enter valid task details.'
                            );

                        }


                        $validated[] =
                            creative_task_data(
                                $task
                            );


                    } catch (
                        InvalidArgumentException $e
                    ) {


                        throw new InvalidArgumentException(
                            'Task '
                            .
                            ($index + 1)
                            .
                            ': '
                            .
                            $e->getMessage()
                        );

                    }

                }



                foreach (
                    $validated
                    as $common
                ) {


                    $metrics =
                        $sheet[
                            'department_code'
                        ]
                        ===
                        'design_video'

                            ? [


                                'poster_count' =>

                                    $common[
                                        'work_type'
                                    ]
                                    ===
                                    'poster'

                                        ? 1

                                        : 0,


                                'video_count' =>

                                    $common[
                                        'work_type'
                                    ]
                                    ===
                                    'video_editing'

                                        ? 1

                                        : 0,


                                'carousel_count' =>

                                    $common[
                                        'work_type'
                                    ]
                                    ===
                                    'carousel'

                                        ? 1

                                        : 0,


                                'design_changes_count' =>
                                    0

                            ]

                            : [];


                    save_work_entry(

                        $sheet,

                        $sheet[
                            'department_code'
                        ],

                        $common,

                        $metrics

                    );

                }



                submit_sheet(
                    $sheet
                );

            }



            /* =================================================
               SUBMIT DAY
            ================================================= */

            elseif (
                $action ===
                'submit_day'
            ) {


                if (
                    !count_value(
                        "SELECT COUNT(*)

                         FROM daily_work_entries

                         WHERE submission_id=?",

                        [
                            $sheet[
                                'id'
                            ]
                        ]
                    )
                ) {


                    throw new InvalidArgumentException(
                        'Add a work entry before submitting.'
                    );

                }



                submit_sheet(
                    $sheet
                );

            }



            /* =================================================
               EXISTING SINGLE ENTRY
            ================================================= */

            else {


                $status =
                    $_POST[
                        'task_status'
                    ]
                    ?? '';


                if (
                    !in_array(
                        $status,
                        [
                            'pending',
                            'completed'
                        ],
                        true
                    )
                ) {


                    throw new InvalidArgumentException(
                        'Choose Pending or Completed.'
                    );

                }



                $remark =
                    text_input(
                        'remark',
                        5000,
                        false
                    );



                $metrics =
                    $action ===
                    'update_assignment'

                        ? []

                        : work_metrics(
                            $sheet[
                                'department_code'
                            ]
                        );



                $timeSpent =
                    null;



                if (
                    $action ===
                    'update_assignment'
                    ||
                    array_key_exists(
                        'time_spent_hours',
                        $_POST
                    )
                ) {


                    $duration =
                        $_POST[
                            'time_spent_hours'
                        ]
                        ?? null;



                    if (
                        !is_string(
                            $duration
                        )
                    ) {


                        throw new InvalidArgumentException(
                            'Enter a duration up to 24 hours.'
                        );

                    }



                    if (
                        $action ===
                        'update_assignment'
                        ||
                        trim(
                            $duration
                        )
                        !==
                        ''
                    ) {


                        $timeSpent =
                            parse_work_duration(
                                $duration
                            );

                    }

                }



                $entryId =
                    null;



                if (
                    $action ===
                    'update_assignment'
                ) {


                    $existing =
                        one(
                            "SELECT id

                             FROM daily_work_entries

                             WHERE
                                submission_id=?
                                AND assignment_id=?",

                            [
                                $sheet[
                                    'id'
                                ],

                                $assignmentId
                            ]
                        );


                    $entryId =
                        $existing

                            ? (int)
                                $existing[
                                    'id'
                                ]

                            : null;

                }



                elseif (
                    !empty(
                        $_POST[
                            'entry_id'
                        ]
                    )
                ) {


                    $entryId =
                        positive_id(
                            $_POST[
                                'entry_id'
                            ]
                        );



                    if (
                        !one(
                            "SELECT id

                             FROM daily_work_entries

                             WHERE
                                id=?
                                AND submission_id=?
                                AND assignment_id IS NULL",

                            [
                                $entryId,
                                $sheet[
                                    'id'
                                ]
                            ]
                        )
                    ) {


                        fail(
                            403,
                            'You can only edit your own work entries.'
                        );

                    }

                }



                $common = [


                    'client_id' =>
                        $client,


                    'task_title' =>
                        $title,


                    'task_status' =>
                        $status,


                    'remark' =>
                        $remark

                ];



                if (
                    $action ===
                    'save_entry'
                    &&
                    creative_work_department(
                        $sheet[
                            'department_code'
                        ],
                        $sheet[
                            'department_name'
                        ]
                    )
                ) {


                    $common[
                        'work_type'
                    ] =
                        work_type_input(
                            $_POST
                        );


                    if (
                        $timeSpent ===
                        null
                    ) {


                        throw new InvalidArgumentException(
                            'Enter the time spent on this task.'
                        );

                    }

                }



                if (
                    $assignmentId !==
                    null
                ) {


                    $common[
                        'assignment_id'
                    ] =
                        $assignmentId;

                }



                if (
                    $assignmentId !==
                    null
                    ||
                    array_key_exists(
                        'time_spent_hours',
                        $_POST
                    )
                ) {


                    $common[
                        'time_spent_minutes'
                    ] =
                        $timeSpent;

                }



                save_work_entry(

                    $sheet,

                    $sheet[
                        'department_code'
                    ],

                    $common,

                    $metrics,

                    $entryId

                );



                if (
                    $assignmentId !==
                    null
                ) {


                    sync_assignment(
                        $assignmentId
                    );

                }



                if (
                    $action ===
                    'update_assignment'
                ) {


                    submit_sheet(
                        $sheet,
                        false
                    );


                    $taskMessage =

                        $assignment[
                            'title'
                        ]

                        .
                        ' · '
                        .
                        $status
                        .
                        ' · '
                        .
                        format_work_duration(
                            $timeSpent
                        )
                        .
                        (
                            $remark !== ''

                                ? ' · ' .
                                    $remark

                                : ''
                        );



                    notify_staff(

                        'work_submitted',

                        $user[
                            'full_name'
                        ]
                        .
                        ' submitted an assigned task update',

                        $date
                        .
                        ' · '
                        .
                        $taskMessage,

                        (int)
                            $sheet[
                                'id'
                            ]

                    );

                }



                elseif (
                    (
                        $_POST[
                            'submit_mode'
                        ]
                        ??
                        'submitted'
                    )
                    ===
                    'submitted'
                ) {


                    submit_sheet(
                        $sheet
                    );

                }



                else {


                    query(
                        "UPDATE daily_work_submissions

                         SET
                            submission_status='draft',
                            submitted_at=NULL,
                            review_status='pending',
                            reviewed_by=NULL,
                            reviewed_at=NULL,
                            manager_remark=NULL

                         WHERE id=?",

                        [
                            $sheet[
                                'id'
                            ]
                        ]
                    );

                }

            }



            db()->commit();



            flash(

                $action ===
                    'save_entry'
                &&
                (
                    $_POST[
                        'submit_mode'
                    ]
                    ?? ''
                )
                ===
                'draft'

                    ? 'Work saved as draft. Submit the day when ready.'

                    : 'Work saved and submitted to your manager.'

            );



            $redirectQuery = [

                'date' =>
                    $date

            ];



            if (
                $employeeView ===
                'assigned'
                &&
                $assignmentDate
            ) {


                $redirectQuery[
                    'assignment_date'
                ] =
                    $assignmentDate;

            }



            redirect(

                (
                    $employeeView ===
                    'daily'

                        ? 'employee-work-history.php'

                        : $viewRoute
                )

                .
                '?'
                .
                http_build_query(
                    $redirectQuery
                )

            );

        }


    } catch (Throwable $e) {


        if (
            db()->inTransaction()
        ) {


            db()->rollBack();

        }


        $error =
            mutation_error(
                $e
            );

    }

}



/* =========================================================
   SHEET
========================================================= */

$sheet =
    one(
        "SELECT
            s.*,
            d.code AS department_code,
            d.name AS department_name

         FROM daily_work_submissions s

         JOIN departments d
            ON d.id=s.department_id

         WHERE
            s.employee_id=?
            AND s.work_date=?",

        [
            $user[
                'id'
            ],
            $date
        ]
    );



$workDepartment =
    $sheet[
        'department_code'
    ]
    ??
    $user[
        'department_code'
    ];



$creativeWork =
    creative_work_department(

        $workDepartment,

        $sheet[
            'department_name'
        ]
        ??
        $user[
            'department_name'
        ]
        ??
        ''

    );



$socialWork =
    $workDepartment ===
    'social_media';



$entries =
    $sheet

        ? rows(
            "SELECT
                e.*,
                c.client_name

             FROM daily_work_entries e

             LEFT JOIN clients c
                ON c.id=e.client_id

             WHERE e.submission_id=?

             ORDER BY e.row_order",

            [
                $sheet[
                    'id'
                ]
            ]
        )

        : [];



/* =========================================================
   ASSIGNMENT ENTRY MAP
========================================================= */

$assignmentEntries = [];


foreach (
    $entries
    as $entry
) {


    if (
        $entry[
            'assignment_id'
        ]
    ) {


        $assignmentEntries[
            $entry[
                'assignment_id'
            ]
        ] =
            $entry;

    }

}



/* =========================================================
   ASSIGNMENTS
========================================================= */

$assignmentDates = [];


if (
    $employeeView ===
    'assigned'
) {


    $assignmentDates =
        rows(
            "SELECT
                work_date,
                COUNT(*) AS task_count,

                GROUP_CONCAT(
                    title
                    ORDER BY id
                    SEPARATOR ' · '
                )
                AS task_titles

             FROM work_assignments

             WHERE
                employee_id=?
                AND deleted_at IS NULL

             GROUP BY work_date

             ORDER BY work_date",

            [
                $user[
                    'id'
                ]
            ]
        );



    $assignments =
        $assignmentDate

            ? rows(
                "SELECT
                    a.*,
                    c.client_name,
                    u.full_name AS assigned_by_name

                 FROM work_assignments a

                 LEFT JOIN clients c
                    ON c.id=a.client_id

                 JOIN users u
                    ON u.id=a.assigned_by

                 WHERE
                    a.employee_id=?
                    AND a.deleted_at IS NULL
                    AND a.work_date=?

                 ORDER BY a.id",

                [
                    $user[
                        'id'
                    ],
                    $assignmentDate
                ]
            )

            : [];



} else {


    $assignments =
        rows(
            "SELECT
                a.*,
                c.client_name,
                u.full_name AS assigned_by_name

             FROM work_assignments a

             LEFT JOIN clients c
                ON c.id=a.client_id

             JOIN users u
                ON u.id=a.assigned_by

             WHERE
                a.employee_id=?
                AND a.deleted_at IS NULL

                AND
                (
                    a.work_date>=?
                    OR a.status='pending'

                    OR EXISTS
                    (
                        SELECT 1

                        FROM daily_work_entries e

                        JOIN daily_work_submissions s
                            ON s.id=e.submission_id

                        WHERE
                            e.assignment_id=a.id
                            AND s.work_date=?
                    )
                )

             ORDER BY
                a.work_date,
                a.id",

            [
                $user[
                    'id'
                ],
                $date,
                $date
            ]
        );

}



/* =========================================================
   ATTACHMENT MAP
========================================================= */

$assignmentAttachmentMap = [];


foreach (
    $assignments
    as $assignment
) {


    $assignmentAttachmentMap[
        $assignment[
            'id'
        ]
    ] =
        employee_assignment_files(
            (int)
                $assignment[
                    'id'
                ]
        );

}



/* =========================================================
   DASHBOARD DATA
========================================================= */

$nextHoliday =
    one(
        "SELECT *

         FROM holidays

         WHERE
            is_published=1
            AND holiday_date>=?

         ORDER BY holiday_date

         LIMIT 1",

        [
            today()
        ]
    );



$latestLeave =
    one(
        "SELECT *

         FROM leave_requests

         WHERE employee_id=?

         ORDER BY
            applied_at DESC,
            id DESC

         LIMIT 1",

        [
            $user[
                'id'
            ]
        ]
    );



$todayAttendance =
    one(
        "SELECT
            login_at,
            logout_at

         FROM employee_attendance_sessions

         WHERE
            employee_id=?
            AND DATE(login_at)=?

         ORDER BY login_at DESC

         LIMIT 1",

        [
            $user[
                'id'
            ],
            today()
        ]
    );



$editEntry = null;


if (
    !empty(
        $_GET[
            'edit_entry'
        ]
    )
) {


    foreach (
        $entries
        as $entry
    ) {


        if (
            (int)
                $entry[
                    'id'
                ]
            ===
            (int)
                $_GET[
                    'edit_entry'
                ]
            &&
            !$entry[
                'assignment_id'
            ]
        ) {


            $editEntry =
                $entry;

        }

    }



    if (
        !$editEntry
    ) {


        fail(
            404,
            'Work entry not found.'
        );

    }

}



/* =========================================================
   PAGE
========================================================= */

page_start(

    [

        'daily' =>
            'Update daily work',

        'history' =>
            'My submitted work',

        'assigned' =>
            'My assigned work'

    ][$employeeView]

    ??

    $user[
        'department_name'
    ]
    .
    ' dashboard',

    $viewRoute

);


error_message(
    $error
);



/* =========================================================
   DASHBOARD
========================================================= */

if (
    $employeeView ===
    'dashboard'
):

?>


<p class="muted">

    Welcome,
    <?= h(
        $user[
            'full_name'
        ]
    ) ?>.

    Update your assigned work and keep your manager informed.

</p>



<div class="stats-grid">


    <a
        class="stat-card"
        href="employee-profile.php"
    >


        <h2>

            My profile &amp; salary

        </h2>


        <strong
            style="font-size:23px"
        >

            <?= h(
                $user[
                    'full_name'
                ]
            ) ?>

        </strong>


        <p>

            View your employee details, salary and benefits

        </p>


    </a>



    <a
        class="stat-card"
        href="payslips.php"
    >


        <h2>

            My payslips

        </h2>


        <strong>

            <?= count_value(
                "SELECT COUNT(*)

                 FROM payslips

                 WHERE employee_id=?",

                [
                    $user[
                        'id'
                    ]
                ]
            ) ?>

        </strong>


        <p>

            View and download your salary slips

        </p>


    </a>



    <a
        class="stat-card"
        href="employee-daily-work.php"
    >


        <h2>

            Update daily work

        </h2>


        <strong>

            +

        </strong>


        <p>

            Enter your daily work and submit to manager

        </p>


    </a>



    <a
        class="stat-card"
        href="employee-assigned-work.php"
    >


        <h2>

            Today's work

        </h2>


        <strong>

            <?= count(
                $assignments
            ) ?>

        </strong>


        <p>

            Assigned and pending tasks

        </p>


    </a>



    <a
        class="stat-card"
        href="employee-work-history.php"
    >


        <h2>

            Work entries

        </h2>


        <strong>

            <?= count(
                $entries
            ) ?>

        </strong>


        <p>

            <?= h(
                $date
            ) ?>

        </p>


    </a>



    <a
        class="stat-card attendance-stat-card"

        href="employee-attendance.php"

        data-attendance-card
    >


        <h2>

            Attendance

        </h2>


        <strong
            style="font-size:23px"
        >

            <?=

            $todayAttendance

                ? h(
                    date(
                        'g:i A',
                        strtotime(
                            $todayAttendance[
                                'login_at'
                            ]
                        )
                    )
                )

                : 'Not logged in'

            ?>

        </strong>


        <p>

            <?=

            $todayAttendance

                ? 'Login time today · '
                    .
                    h(
                        date(
                            'd M Y',
                            strtotime(
                                $todayAttendance[
                                    'login_at'
                                ]
                            )
                        )
                    )

                : 'Use Login in the sidebar to record today’s time'

            ?>

        </p>


        <?php if (
            $todayAttendance
            &&
            $todayAttendance[
                'logout_at'
            ]
        ): ?>


            <p
                data-attendance-logout
            >

                Logout time ·

                <?= h(
                    date(
                        'g:i A',
                        strtotime(
                            $todayAttendance[
                                'logout_at'
                            ]
                        )
                    )
                ) ?>

            </p>


        <?php endif; ?>


        <span
            class="attendance-card-link"
        >

            View day-wise attendance →

        </span>


    </a>



    <a
        class="stat-card"
        href="my-leave-requests.php"
    >


        <h2>

            Leave status

        </h2>


        <strong
            style="font-size:23px"
        >

            <?= h(

                $latestLeave

                    ? ucfirst(
                        $latestLeave[
                            'status'
                        ]
                    )

                    : 'No requests'

            ) ?>

        </strong>


        <p>

            <?= h(
                $latestLeave[
                    'from_date'
                ]
                ??
                'Apply for leave'
            ) ?>

        </p>


    </a>



    <a
        class="stat-card"
        href="admin-holidays.php"
    >


        <h2>

            Next holiday

        </h2>


        <strong
            style="font-size:23px"
        >

            <?= h(

                $nextHoliday

                    ? date(
                        'd M',
                        strtotime(
                            $nextHoliday[
                                'holiday_date'
                            ]
                        )
                    )

                    : 'None scheduled'

            ) ?>

        </strong>


        <p>

            <?= h(
                $nextHoliday[
                    'holiday_name'
                ]
                ??
                'View holiday calendar'
            ) ?>

        </p>


    </a>


</div>


<?php endif; ?>



<?php
/* =========================================================
   NORMAL DATE FILTER
========================================================= */

if (
    $employeeView !==
    'dashboard'
    &&
    $employeeView !==
    'assigned'
):

?>


<form
    method="get"
    class="filters"
>


    <div class="field">


        <label for="date">

            Work date

        </label>


        <input

            id="date"

            name="date"

            type="date"

            value="<?= h(
                $date
            ) ?>"

            required
        >


    </div>


    <button
        class="primary"
        type="submit"
    >

        View work

    </button>


</form>


<?php endif; ?>



<?php
/* =========================================================
   ASSIGNED WORK
========================================================= */

if (
    $employeeView ===
    'assigned'
):

?>


<style>

.employee-assigned-task {

    margin-bottom: 24px;

}


.employee-task-content {

    margin-top: 16px;

    padding: 16px;

    background:
        rgba(0,0,0,.025);

    border-radius: 10px;

}


.employee-task-content-title {

    display: block;

    margin-bottom: 8px;

    font-size: 13px;

    font-weight: 700;

}


.employee-task-message {

    line-height: 1.7;

}


.employee-reference-link {

    display: inline-flex;

    align-items: center;

    padding: 9px 14px;

    border-radius: 7px;

    text-decoration: none;

    font-weight: 600;

    border: 1px solid
        rgba(0,0,0,.10);

}


.employee-file-list {

    display: flex;

    flex-direction: column;

    gap: 10px;

}


.employee-file-row {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    padding: 11px 13px;

    border: 1px solid
        rgba(0,0,0,.10);

    border-radius: 8px;

}


.employee-file-name {

    overflow-wrap: anywhere;

}


.employee-file-buttons {

    display: flex;

    gap: 7px;

    flex-shrink: 0;

}


.employee-file-button {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 7px 11px;

    border: 1px solid
        rgba(0,0,0,.12);

    border-radius: 6px;

    text-decoration: none;

    font-size: 12px;

    font-weight: 600;

}


.employee-image-grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(
                140px,
                1fr
            )
        );

    gap: 10px;

    margin-top: 12px;

}


.employee-image-grid img {

    width: 100%;

    height: 150px;

    object-fit: cover;

    border-radius: 8px;

}


.employee-video-preview {

    width: 100%;

    max-width: 600px;

    margin-top: 12px;

    border-radius: 8px;

}


.employee-work-control {

    margin-top: 20px;

    padding: 20px;

    border: 1px solid
        rgba(0,0,0,.09);

    border-radius: 12px;

}


.employee-time-label {

    display: block;

    margin-bottom: 5px;

    font-size: 12px;

    font-weight: 700;

    opacity: .65;

}


.employee-live-timer {

    margin-bottom: 18px;

    font-size:
        clamp(
            30px,
            5vw,
            44px
        );

    font-weight: 700;

    letter-spacing: 2px;

    font-variant-numeric:
        tabular-nums;

}


.employee-task-status {

    display: inline-flex;

    align-items: center;

    padding: 6px 11px;

    margin-bottom: 12px;

    border-radius: 30px;

    font-size: 12px;

    font-weight: 700;

}


.employee-task-status.not-started {

    background:
        rgba(107,114,128,.12);

}


.employee-task-status.running {

    background:
        rgba(37,99,235,.10);

    color: #2563eb;

}


.employee-task-status.finished {

    background:
        rgba(21,128,61,.10);

    color: #15803d;

}


.employee-remark-box {

    margin-top: 16px;

}


.employee-task-action-row {

    margin-top: 15px;

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

}



.employee-assigned-task {
    padding: 0;
    overflow: hidden;
}

.employee-task-summary {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 16px 18px;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
    font: inherit;
}

.employee-task-summary:hover {
    background: rgba(0,0,0,.025);
}

.employee-task-summary-main {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.employee-task-number {
    font-size: 15px;
    font-weight: 800;
    white-space: nowrap;
}

.employee-task-summary-title {
    font-size: 14px;
    font-weight: 600;
    opacity: .82;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: min(52vw, 520px);
}

.employee-task-summary-side {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.employee-task-summary .employee-task-status {
    margin-bottom: 0;
}

.employee-task-chevron {
    font-size: 18px;
    line-height: 1;
    transition: transform .2s ease;
}

.employee-assigned-task.is-open .employee-task-chevron {
    transform: rotate(180deg);
}

.employee-task-details {
    display: none;
    padding: 0 18px 18px;
    border-top: 1px solid rgba(0,0,0,.07);
}

.employee-assigned-task.is-open .employee-task-details {
    display: block;
}

@media
(max-width:767px) {


    .employee-file-row {

        align-items: flex-start;

        flex-direction: column;

    }


    .employee-file-buttons {

        width: 100%;

        flex-wrap: wrap;

    }

    .employee-task-summary {
        align-items: flex-start;
        padding: 14px;
    }

    .employee-task-summary-title {
        max-width: 58vw;
    }

    .employee-task-details {
        padding: 0 14px 14px;
    }

}

</style>



<p>


    <a

        class="button secondary"

        href="download-assignments.php<?=

            $assignmentDate

                ? '?assignment_date='
                    .
                    h(
                        $assignmentDate
                    )

                : ''

        ?>"
    >

        Download Excel

    </a>


</p>



<?php if (
    !$assignmentDate
): ?>


<section
    class="panel"
    id="assigned-work"
>


    <h2>

        Assigned work by date

    </h2>



    <div class="table-scroll">


        <table>


            <thead>


                <tr>

                    <th>
                        Assignment date
                    </th>

                    <th>
                        Tasks
                    </th>

                    <th>
                        Assigned work
                    </th>

                    <th></th>

                </tr>


            </thead>



            <tbody>


                <?php


                if (
                    !$assignmentDates
                ) {


                    empty_row(
                        4,
                        'No assigned work yet.'
                    );

                }



                foreach (
                    $assignmentDates
                    as $day
                ):

                ?>


                    <tr>


                        <td>

                            <?= h(
                                $day[
                                    'work_date'
                                ]
                            ) ?>

                        </td>



                        <td>

                            <?= h(
                                $day[
                                    'task_count'
                                ]
                            ) ?>

                        </td>



                        <td class="wrap">

                            <?= h(
                                $day[
                                    'task_titles'
                                ]
                            ) ?>

                        </td>



                        <td>


                            <a
                                class="button secondary"

                                href="employee-assigned-work.php?assignment_date=<?= h(
                                    $day[
                                        'work_date'
                                    ]
                                ) ?>"
                            >

                                View work

                            </a>


                        </td>


                    </tr>


                <?php endforeach; ?>


            </tbody>


        </table>


    </div>


</section>



<?php else: ?>


<section
    class="panel"
    id="assigned-work"
>


    <div class="panel-heading">


        <h2>

            Assigned work ·

            <?= h(
                $assignmentDate
            ) ?>

        </h2>



        <a

            class="button secondary"

            href="employee-assigned-work.php"
        >

            All dates

        </a>


    </div>



    <?php if (
        !$assignments
    ): ?>


        <p class="muted">

            No assigned tasks for this date.

        </p>


    <?php endif; ?>



    <?php


    foreach (
        $assignments
        as $taskIndex => $assignment
    ):


        $attachments =
            $assignmentAttachmentMap[
                $assignment[
                    'id'
                ]
            ]
            ?? [];


        $referenceLink =
            trim(
                (string) (
                    $assignment[
                        'reference_link'
                    ]
                    ?? ''
                )
            );


        $started =
            !empty(
                $assignment[
                    'started_at'
                ]
            );


        $finished =
            !empty(
                $assignment[
                    'completed_at'
                ]
            );


        $timeSpentSeconds =
            (int) (
                $assignment[
                    'time_spent_seconds'
                ]
                ?? 0
            );


        $startTimestamp =
            $started

                ? strtotime(
                    $assignment[
                        'started_at'
                    ]
                )

                : 0;


    ?>


        <article
            class="work-card employee-assigned-task"
            data-assignment-accordion
        >


            <button
                type="button"
                class="employee-task-summary"
                data-assignment-toggle
                aria-expanded="false"
                aria-controls="assignment-task-details-<?= (int) $assignment['id'] ?>"
            >

                <span class="employee-task-summary-main">

                    <span class="employee-task-number">
                        Task <?= $taskIndex + 1 ?>
                    </span>

                    <span class="employee-task-summary-title">
                        <?= h($assignment['title']) ?>
                    </span>

                </span>

                <span class="employee-task-summary-side">

                    <?php if ($finished): ?>

                        <span class="employee-task-status finished">
                            FINISHED
                        </span>

                    <?php elseif ($started): ?>

                        <span class="employee-task-status running">
                            WORKING
                        </span>

                    <?php else: ?>

                        <span class="employee-task-status not-started">
                            READY
                        </span>

                    <?php endif; ?>

                    <span class="employee-task-chevron" aria-hidden="true">⌄</span>

                </span>

            </button>


            <div
                class="employee-task-details"
                id="assignment-task-details-<?= (int) $assignment['id'] ?>"
                data-assignment-details
            >


            <!-- =================================================
                 MESSAGE / TASK BRIEF
            ================================================== -->

            <div class="employee-task-content">


                <span
                    class="employee-task-content-title"
                >

                    Message / Task Brief

                </span>


                <div class="employee-task-message">

                    <?= nl2br(
                        h(
                            $assignment[
                                'description'
                            ]
                        )
                    ) ?>

                </div>


            </div>



            <!-- =================================================
                 REFERENCE LINK
            ================================================== -->

            <div class="employee-task-content">


                <span
                    class="employee-task-content-title"
                >

                    Reference / Drive Link

                </span>


                <?php if (
                    $referenceLink !==
                    ''
                ): ?>


                    <a

                        class="employee-reference-link"

                        href="<?= h(
                            $referenceLink
                        ) ?>"

                        target="_blank"

                        rel="noopener noreferrer"
                    >

                        Open Reference / Drive Link ↗

                    </a>


                <?php else: ?>


                    <span class="muted">

                        No reference link added.

                    </span>


                <?php endif; ?>


            </div>



            <!-- =================================================
                 ATTACHED FILES
            ================================================== -->

            <div class="employee-task-content">


                <span
                    class="employee-task-content-title"
                >

                    Attached Files

                </span>


                <?php if (
                    $attachments
                ): ?>


                    <div class="employee-file-list">


                        <?php


                        foreach (
                            $attachments
                            as $attachment
                        ):


                            $mime =
                                strtolower(
                                    (string) (
                                        $attachment[
                                            'mime_type'
                                        ]
                                        ?? ''
                                    )
                                );


                            $openUrl =
                                'employee-assigned-work.php'
                                .
                                '?task_file='
                                .
                                (int) $attachment['id']
                                .
                                '&mode=view';


                            $downloadUrl =
                                'employee-assigned-work.php'
                                .
                                '?task_file='
                                .
                                (int) $attachment['id']
                                .
                                '&mode=download';


                            $fileName =
                                $attachment[
                                    'original_name'
                                ];



                            if (
                                strpos($mime, 'image/') === 0
                            ) {


                                $icon =
                                    '🖼️';

                            }



                            elseif (
                                strpos($mime, 'video/') === 0
                            ) {


                                $icon =
                                    '🎬';

                            }



                            elseif (
                                $mime ===
                                'application/pdf'
                            ) {


                                $icon =
                                    '📄';

                            }



                            else {


                                $icon =
                                    '📎';

                            }


                        ?>


                            <div
                                class="employee-file-row"
                            >


                                <div
                                    class="employee-file-name"
                                >

                                    <?= $icon ?>

                                    <?= h(
                                        $fileName
                                    ) ?>

                                </div>



                                <div
                                    class="employee-file-buttons"
                                >


                                    <!-- OPEN -->

                                    <a

                                        class="employee-file-button"

                                        href="<?= h(
                                            $openUrl
                                        ) ?>"

                                        target="_blank"

                                        rel="noopener noreferrer"
                                    >

                                        Open

                                    </a>



                                    <!-- DOWNLOAD -->

                                    <a

                                        class="employee-file-button"

                                        href="<?= h(
                                            $downloadUrl
                                        ) ?>"
                                    >

                                        Download

                                    </a>


                                </div>


                            </div>


                        <?php endforeach; ?>


                    </div>



                    <!-- =============================================
                         IMAGE PREVIEWS
                    ============================================== -->

                    <div
                        class="employee-image-grid"
                    >


                        <?php


                        foreach (
                            $attachments
                            as $attachment
                        ):


                            $mime =
                                strtolower(
                                    (string) (
                                        $attachment[
                                            'mime_type'
                                        ]
                                        ?? ''
                                    )
                                );


                            if (
                                strpos($mime, 'image/') !== 0
                            ) {


                                continue;

                            }


                            $imageUrl =
                                'employee-assigned-work.php'
                                .
                                '?task_file='
                                .
                                (int) $attachment['id']
                                .
                                '&mode=view';


                        ?>


                            <a

                                href="<?= h(
                                    $imageUrl
                                ) ?>"

                                target="_blank"

                                rel="noopener noreferrer"
                            >


                                <img

                                    src="<?= h(
                                        $imageUrl
                                    ) ?>"

                                    alt="<?= h(
                                        $attachment[
                                            'original_name'
                                        ]
                                    ) ?>"

                                    loading="lazy"
                                >


                            </a>


                        <?php endforeach; ?>


                    </div>



                    <!-- =============================================
                         VIDEO PREVIEWS
                    ============================================== -->

                    <?php


                    foreach (
                        $attachments
                        as $attachment
                    ):


                        $mime =
                            strtolower(
                                (string) (
                                    $attachment[
                                        'mime_type'
                                    ]
                                    ?? ''
                                )
                            );


                        if (
                            strpos($mime, 'video/') !== 0
                        ) {


                            continue;

                        }


                        $videoUrl =
                            'employee-assigned-work.php'
                            .
                            '?task_file='
                            .
                            (int) $attachment['id']
                            .
                            '&mode=view';


                    ?>


                        <video

                            class="employee-video-preview"

                            controls

                            playsinline

                            preload="metadata"
                        >


                            <source

                                src="<?= h(
                                    $videoUrl
                                ) ?>"

                                type="<?= h(
                                    $mime
                                ) ?>"
                            >


                            Your browser does not support video playback.


                        </video>


                    <?php endforeach; ?>


                <?php else: ?>


                    <span class="muted">

                        No attached files.

                    </span>


                <?php endif; ?>


            </div>



            <!-- =================================================
                 START / END / TIMER / REMARK
            ================================================== -->

            <div
                class="employee-work-control"
            >



                <!-- =================================================
                     NOT STARTED
                ================================================== -->

                <?php if (
                    !$started
                ): ?>


                    <span
                        class="employee-task-status not-started"
                    >

                        NOT STARTED

                    </span>


                    <span
                        class="employee-time-label"
                    >

                        Time

                    </span>


                    <div
                        class="employee-live-timer"
                    >

                        00:00:00

                    </div>



                    <form method="post">


                        <?= csrf_field() ?>


                        <input
                            type="hidden"

                            name="action"

                            value="start_assignment"
                        >


                        <input
                            type="hidden"

                            name="assignment_id"

                            value="<?= (int)
                                $assignment[
                                    'id'
                                ]
                            ?>"
                        >


                        <div
                            class="employee-task-action-row"
                        >


                            <button
                                class="primary"

                                type="submit"
                            >

                                Start Work

                            </button>


                        </div>


                    </form>


                <?php endif; ?>



                <!-- =================================================
                     WORKING
                ================================================== -->

                <?php if (
                    $started
                    &&
                    !$finished
                ): ?>


                    <span
                        class="employee-task-status running"
                    >

                        WORKING

                    </span>



                    <span
                        class="employee-time-label"
                    >

                        Time

                    </span>



                    <div

                        class="employee-live-timer"

                        data-running-assignment-timer

                        data-start="<?= (int)
                            $startTimestamp
                        ?>"
                    >

                        00:00:00

                    </div>



                    <form method="post">


                        <?= csrf_field() ?>


                        <input
                            type="hidden"

                            name="action"

                            value="end_assignment"
                        >


                        <input
                            type="hidden"

                            name="assignment_id"

                            value="<?= (int)
                                $assignment[
                                    'id'
                                ]
                            ?>"
                        >



                        <!-- REMARK -->

                        <div
                            class="field full employee-remark-box"
                        >


                            <label
                                for="remark-<?= (int)
                                    $assignment[
                                        'id'
                                    ]
                                ?>"
                            >

                                Remark

                            </label>


                            <textarea

                                id="remark-<?= (int)
                                    $assignment[
                                        'id'
                                    ]
                                ?>"

                                name="remark"

                                maxlength="5000"

                                placeholder="Enter completed work details, output link, issue or remark"

                            ><?= h(
                                $assignment[
                                    'employee_remark'
                                ]
                                ?? ''
                            ) ?></textarea>


                        </div>



                        <div
                            class="employee-task-action-row"
                        >


                            <button
                                class="primary"

                                type="submit"
                            >

                                End Work

                            </button>


                        </div>


                    </form>


                <?php endif; ?>



                <!-- =================================================
                     FINISHED
                ================================================== -->

                <?php if (
                    $finished
                ): ?>


                    <span
                        class="employee-task-status finished"
                    >

                        FINISHED

                    </span>



                    <span
                        class="employee-time-label"
                    >

                        Time Taken

                    </span>



                    <div
                        class="employee-live-timer"
                    >

                        <?= h(
                            employee_assignment_duration(
                                $timeSpentSeconds
                            )
                        ) ?>

                    </div>



                    <?php if (
                        !empty(
                            $assignment[
                                'started_at'
                            ]
                        )
                    ): ?>


                        <p class="muted">

                            Started:

                            <?= h(
                                date(
                                    'd M Y, h:i A',

                                    strtotime(
                                        $assignment[
                                            'started_at'
                                        ]
                                    )
                                )
                            ) ?>

                        </p>


                    <?php endif; ?>



                    <?php if (
                        !empty(
                            $assignment[
                                'completed_at'
                            ]
                        )
                    ): ?>


                        <p class="muted">

                            Finished:

                            <?= h(
                                date(
                                    'd M Y, h:i A',

                                    strtotime(
                                        $assignment[
                                            'completed_at'
                                        ]
                                    )
                                )
                            ) ?>

                        </p>


                    <?php endif; ?>



                    <div
                        class="employee-task-content"
                    >


                        <span
                            class="employee-task-content-title"
                        >

                            Remark

                        </span>


                        <?php if (
                            !empty(
                                $assignment[
                                    'employee_remark'
                                ]
                            )
                        ): ?>


                            <?= nl2br(
                                h(
                                    $assignment[
                                        'employee_remark'
                                    ]
                                )
                            ) ?>


                        <?php else: ?>


                            <span class="muted">

                                No remark added.

                            </span>


                        <?php endif; ?>


                    </div>


                <?php endif; ?>


            </div>


            </div>


        </article>


    <?php endforeach; ?>


</section>


<?php endif; ?>


<?php endif; ?>



<?php
/* =========================================================
   HISTORY
========================================================= */

if (
    $employeeView ===
    'history'
):

?>


<p>


    <a
        class="button secondary"

        href="download-work.php?date=<?= h(
            $date
        ) ?>"
    >

        Download this day

    </a>


    <a
        class="button secondary"

        href="download-work.php?all_dates=1"
    >

        Download all submitted work

    </a>


</p>



<section
    class="panel"
    id="daily-sheet"
>


    <div class="panel-heading">


        <h2>

            Daily work sheet ·

            <?= h(
                $date
            ) ?>

        </h2>


        <?=

        $sheet

            ? status_badge(
                $sheet[
                    'submission_status'
                ]
            )

            : ''

        ?>


    </div>



    <?php if (
        $sheet
        &&
        $sheet[
            'manager_remark'
        ]
    ): ?>


        <p>


            <strong>

                Manager feedback:

            </strong>


            <?= h(
                $sheet[
                    'manager_remark'
                ]
            ) ?>


            ·


            <?= status_badge(
                $sheet[
                    'review_status'
                ]
            ) ?>


        </p>


    <?php endif; ?>



    <div class="table-scroll">


        <table>


            <thead>


                <tr>

                    <th>
                        S.no
                    </th>

                    <th>
                        Date
                    </th>

                    <th>
                        Client
                    </th>

                    <th>
                        Task
                    </th>

                    <th>
                        Time spent
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Remark
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>


            </thead>



            <tbody>


                <?php


                if (
                    !$entries
                ) {


                    empty_row(
                        8
                    );

                }



                foreach (
                    $entries
                    as $index => $entry
                ):

                ?>


                    <tr>


                        <td>

                            <?= $index + 1 ?>

                        </td>



                        <td>

                            <?= h(
                                $date
                            ) ?>

                        </td>



                        <td>

                            <?= h(
                                $entry[
                                    'client_name'
                                ]
                            ) ?>

                        </td>



                        <td class="wrap">


                            <?php if (
                                !empty(
                                    $entry[
                                        'work_type'
                                    ]
                                )
                            ): ?>


                                <span
                                    class="work-type-label"
                                >

                                    <?= h(
                                        work_type_label(
                                            $entry[
                                                'work_type'
                                            ]
                                        )
                                    ) ?>

                                </span>


                            <?php endif; ?>


                            <?= h(
                                $entry[
                                    'task_title'
                                ]
                            ) ?>


                        </td>



                        <td>


                            <?= h(

                                $entry[
                                    'time_spent_minutes'
                                ]
                                ===
                                null

                                    ? '?'

                                    : format_work_duration(
                                        $entry[
                                            'time_spent_minutes'
                                        ]
                                    )

                            ) ?>


                        </td>



                        <td>

                            <?= status_badge(
                                $entry[
                                    'task_status'
                                ]
                            ) ?>

                        </td>



                        <td class="wrap">

                            <?= h(
                                $entry[
                                    'remark'
                                ]
                            ) ?>

                        </td>



                        <td>


                            <div class="actions">


                                <?php

                                detail_button(
                                    'entry-'
                                    .
                                    $entry[
                                        'id'
                                    ]
                                );

                                ?>


                                <?php if (
                                    !$entry[
                                        'assignment_id'
                                    ]
                                ): ?>


                                    <a

                                        class="icon-button"

                                        href="employee-daily-work.php?date=<?= h(
                                            $date
                                        ) ?>&amp;edit_entry=<?= (int)
                                            $entry[
                                                'id'
                                            ]
                                        ?>#add-work"

                                        title="Edit work"

                                        aria-label="Edit work"
                                    >

                                        <?= icon(
                                            'edit'
                                        ) ?>

                                    </a>


                                <?php endif; ?>


                            </div>


                        </td>


                    </tr>


                <?php endforeach; ?>


            </tbody>


        </table>


    </div>



    <?php if (
        $entries
    ): ?>


        <form
            method="post"

            style="margin-top:20px"
        >


            <?= csrf_field() ?>


            <input

                type="hidden"

                name="action"

                value="submit_day"
            >


            <button

                class="primary"

                type="submit"
            >

                Submit day to manager

            </button>


        </form>


    <?php endif; ?>


</section>


<?php endif; ?>



<?php
/* =========================================================
   DAILY WORK
========================================================= */

if (
    $employeeView ===
    'daily'
) {


    require __DIR__
        .
        '/daily-work-form.php';

}



/* =========================================================
   DETAIL DIALOG
========================================================= */

foreach (
    $entries
    as $entry
) {


    detail_dialog(

        'entry-'
        .
        $entry[
            'id'
        ],

        'Work details',

        array_merge(

            [

                'Date' =>
                    $date,

                'Client' =>
                    $entry[
                        'client_name'
                    ]

            ],

            work_details(
                $entry,
                $workDepartment
            )

        )

    );

}



?>


<!-- =========================================================
     ASSIGNED TASK ACCORDION
========================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const taskCards = Array.from(
        document.querySelectorAll('[data-assignment-accordion]')
    );

    taskCards.forEach(function (card) {
        const toggle = card.querySelector('[data-assignment-toggle]');
        const details = card.querySelector('[data-assignment-details]');

        if (!toggle || !details) {
            return;
        }

        toggle.addEventListener('click', function () {
            const willOpen = !card.classList.contains('is-open');

            taskCards.forEach(function (otherCard) {
                otherCard.classList.remove('is-open');

                const otherToggle = otherCard.querySelector(
                    '[data-assignment-toggle]'
                );

                if (otherToggle) {
                    otherToggle.setAttribute('aria-expanded', 'false');
                }
            });

            if (willOpen) {
                card.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            }
        });
    });
});
</script>


<!-- =========================================================
     LIVE TIMER
========================================================= -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const timers =
            document.querySelectorAll(
                '[data-running-assignment-timer]'
            );


        timers.forEach(
            function (timer) {


                const startTimestamp =
                    Number(
                        timer.dataset.start
                    );


                if (
                    !startTimestamp
                ) {

                    return;

                }



                function updateTimer() {


                    const currentTimestamp =
                        Math.floor(
                            Date.now()
                            /
                            1000
                        );


                    let seconds =
                        Math.max(
                            0,
                            currentTimestamp
                            -
                            startTimestamp
                        );


                    const hours =
                        Math.floor(
                            seconds
                            /
                            3600
                        );


                    seconds =
                        seconds
                        %
                        3600;


                    const minutes =
                        Math.floor(
                            seconds
                            /
                            60
                        );


                    const remainingSeconds =
                        seconds
                        %
                        60;



                    timer.textContent =

                        String(
                            hours
                        )
                        .padStart(
                            2,
                            '0'
                        )

                        +

                        ':'

                        +

                        String(
                            minutes
                        )
                        .padStart(
                            2,
                            '0'
                        )

                        +

                        ':'

                        +

                        String(
                            remainingSeconds
                        )
                        .padStart(
                            2,
                            '0'
                        );

                }



                updateTimer();



                window.setInterval(
                    updateTimer,
                    1000
                );


            }
        );

    }
);

</script>


<?php

page_end();

?>