<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';
require_once __DIR__ . '/office-timing.php';
require_once __DIR__ . '/salary-calc.php';


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
    session_write_close(); // do not hold the session lock while a video streams

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

            $pausedOthers = rows('SELECT id,title FROM work_assignments WHERE employee_id=? AND id<>? AND deleted_at IS NULL AND completed_at IS NULL AND timer_resumed_at IS NOT NULL FOR UPDATE', [$user['id'], $assignmentId]);
            foreach ($pausedOthers as $other) {
                query('UPDATE work_assignments SET timer_accumulated_seconds=timer_accumulated_seconds+GREATEST(0,TIMESTAMPDIFF(SECOND,timer_resumed_at,NOW())), timer_resumed_at=NULL WHERE id=?', [$other['id']]);
            }

            if (empty($assignment['started_at'])) {
                // First start: the clock begins now.
                query("UPDATE work_assignments SET started_at=NOW(), timer_resumed_at=NOW(), timer_accumulated_seconds=0, completed_at=NULL, time_spent_seconds=NULL, time_spent_minutes=0, status='pending' WHERE id=?", [$assignmentId]);
                $timerMessage = 'Work started. Timer is running.';
            } elseif (empty($assignment['timer_resumed_at'])) {
                // Resume after a pause: earlier time stays in timer_accumulated_seconds.
                query('UPDATE work_assignments SET timer_resumed_at=NOW() WHERE id=?', [$assignmentId]);
                $timerMessage = 'Timer resumed.';
            } else {
                $timerMessage = 'Timer is already running.';
            }

            db()->commit();
            if ($pausedOthers) $timerMessage .= ' "' . implode('", "', array_column($pausedOthers, 'title')) . '" was paused — you can resume it later.';
            flash($timerMessage);
            redirect('employee-assigned-work.php?task=' . $assignmentId);
        }

        /* =====================================================
           PAUSE ASSIGNED TASK
        ===================================================== */
        elseif ($action === 'pause_assignment') {
            $assignmentId = positive_id($_POST['assignment_id'] ?? null);
            db()->beginTransaction();
            $assignment = one('SELECT * FROM work_assignments WHERE id=? AND employee_id=? AND deleted_at IS NULL FOR UPDATE', [$assignmentId, $user['id']]);
            if (!$assignment) {
                fail(403, 'You can only pause your own assigned work.');
            }
            if (!empty($assignment['completed_at'])) {
                throw new InvalidArgumentException('This task is already finished.');
            }
            if (!empty($assignment['timer_resumed_at'])) {
                query('UPDATE work_assignments SET timer_accumulated_seconds=timer_accumulated_seconds+GREATEST(0,TIMESTAMPDIFF(SECOND,timer_resumed_at,NOW())), timer_resumed_at=NULL WHERE id=?', [$assignmentId]);
            }
            db()->commit();
            flash('Timer paused. Resume whenever you continue this task.');
            redirect('employee-assigned-work.php?task=' . $assignmentId);
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

            // Worked time = time before any pauses + the current running stretch (computed in SQL to avoid timezone drift).
            $timeSpentSeconds = (int) (one('SELECT timer_accumulated_seconds + IF(timer_resumed_at IS NULL, 0, GREATEST(0, TIMESTAMPDIFF(SECOND, timer_resumed_at, NOW()))) AS secs FROM work_assignments WHERE id=?', [$assignmentId])['secs'] ?? 0);



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
                    timer_resumed_at=NULL,
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



            $nextTask = one("SELECT id,title FROM work_assignments WHERE employee_id=? AND deleted_at IS NULL AND status<>'completed' AND completed_at IS NULL ORDER BY started_at IS NULL, FIELD(priority,'urgent','high','normal'), work_date, id LIMIT 1", [$user['id']]);
            flash('Task finished and submitted successfully.' . ($nextTask ? ' Next task: "' . $nextTask['title'] . '" — click Start work when you begin.' : ' All your tasks are done.'));
            redirect('employee-assigned-work.php?task=' . ($nextTask ? (int) $nextTask['id'] : $assignmentId));

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

                // Optional: link this entry to one of the employee's assigned tasks.
                if (!empty($_POST['assignment_id'])) {
                    $linkedAssignment = one('SELECT id,client_id FROM work_assignments WHERE id=? AND employee_id=? AND deleted_at IS NULL', [positive_id($_POST['assignment_id']), $user['id']]);
                    if (!$linkedAssignment) {
                        throw new InvalidArgumentException('Choose one of your assigned tasks.');
                    }
                    $assignmentId = (int) $linkedAssignment['id'];
                    if ($client === null && $linkedAssignment['client_id'] !== null) {
                        $client = (int) $linkedAssignment['client_id'];
                    }
                }

                $outputLink = trim((string) ($_POST['output_link'] ?? ''));
                if ($outputLink !== '' && (mb_strlen($outputLink) > 500 || !preg_match('~^https?://[^\s]+$~i', $outputLink))) {
                    throw new InvalidArgumentException('Enter a valid output link starting with http:// or https://.');
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



                elseif ($action === 'save_entry') {
                    // An assigned task has one entry per day: update it instead of adding a duplicate.
                    if ($assignmentId !== null) {
                        $existing = one('SELECT id FROM daily_work_entries WHERE submission_id=? AND assignment_id=?', [$sheet['id'], $assignmentId]);
                        if ($existing) $entryId = (int) $existing['id'];
                    }
                    if ($entryId === null && !empty($_POST['entry_id'])) {
                        $entryId = positive_id($_POST['entry_id']);
                        if (!one('SELECT id FROM daily_work_entries WHERE id=? AND submission_id=?', [$entryId, $sheet['id']])) {
                            fail(403, 'You can only edit your own work entries.');
                        }
                    }
                    $previousAssignmentId = $entryId !== null ? (int) (one('SELECT assignment_id FROM daily_work_entries WHERE id=?', [$entryId])['assignment_id'] ?? 0) : 0;
                }

                elseif (
                    !empty(
                        $_POST[
                            'entry_id'
                        ]
                    )
                ) {
                    $entryId = positive_id($_POST['entry_id']);
                    if (!one('SELECT id FROM daily_work_entries WHERE id=? AND submission_id=? AND assignment_id IS NULL', [$entryId, $sheet['id']])) {
                        fail(403, 'You can only edit your own work entries.');
                    }
                }

                $common = [
                    'client_id' => $client,
                    'task_title' => $title,
                    'task_status' => $status,
                    'remark' => $remark
                ];

                if ($action === 'save_entry') {
                    $isCreative = creative_work_department($sheet['department_code'], $sheet['department_name']);
                    $allowedTypes = department_work_types($sheet['department_code'], $sheet['department_name']);
                    // Designers must pick a type; other departments may pick one of their own types.
                    $common['work_type'] = work_type_input($_POST, $isCreative, $allowedTypes ?: null);
                    if ($isCreative && $timeSpent === null) {
                        throw new InvalidArgumentException('Enter the time spent on this task.');
                    }
                    $common['output_link'] = $outputLink !== '' ? $outputLink : null;
                    $common['assignment_id'] = $assignmentId;
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

                // Entry moved off a previously linked assigned task: refresh that task's status too.
                if (!empty($previousAssignmentId) && $previousAssignmentId !== $assignmentId) {
                    sync_assignment($previousAssignmentId);
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

                        ? (($_POST['after_save'] ?? '') === 'another' ? 'employee-daily-work.php' : 'employee-work-history.php')

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

$departmentRequirements=[];
if ($employeeView==='assigned') {
    $departmentRequirements=rows("SELECT r.*,c.client_name,d.name AS department_name FROM client_requirements r JOIN clients c ON c.id=r.client_id LEFT JOIN departments d ON d.id=r.department_id WHERE c.deleted_at IS NULL AND c.is_active=1 AND (r.department_id=? OR EXISTS (SELECT 1 FROM requirement_assignments ra WHERE ra.requirement_id=r.id AND ra.employee_id=?)) ORDER BY r.due_date,r.created_at DESC",[$user['department_id'],$user['id']]);
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

if ($employeeView === 'dashboard'):

$workTrendRows=rows("SELECT s.work_date,COUNT(e.id) AS task_count,SUM(e.task_status='completed') AS completed_count FROM daily_work_submissions s LEFT JOIN daily_work_entries e ON e.submission_id=s.id WHERE s.employee_id=? AND s.submission_status='submitted' AND s.work_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 6 DAY) AND CURDATE() GROUP BY s.work_date ORDER BY s.work_date",[$user['id']]);
$workTrendByDate=[]; foreach ($workTrendRows as $trendRow) $workTrendByDate[$trendRow['work_date']]=$trendRow;
$workTrend=[]; for ($offset=6;$offset>=0;$offset--) { $trendDate=(new DateTimeImmutable('today'))->modify('-'.$offset.' days')->format('Y-m-d'); $trend=$workTrendByDate[$trendDate]??null; $workTrend[]=['date'=>$trendDate,'submitted'=>$trend!==null,'task_count'=>(int)($trend['task_count']??0),'completed_count'=>(int)($trend['completed_count']??0)]; }
$dashboardNotifications=rows('SELECT n.id,n.title,n.message,n.created_at,n.read_at,sender.full_name AS sender_name FROM notifications n LEFT JOIN users sender ON sender.id=n.sender_id WHERE n.recipient_id=? AND n.created_at>=DATE_SUB(NOW(),INTERVAL 1 DAY) ORDER BY n.created_at DESC,n.id DESC',[$user['id']]);
$unreadNotifications=count(array_filter($dashboardNotifications,static fn($n)=>!$n['read_at']));

// "Your day" summary
$todayYmd=today();
$hour=(int)date('G');
$greeting=$hour<12?'Good morning':($hour<17?'Good afternoon':'Good evening');
$firstName=preg_split('/\s+/',trim((string)$user['full_name']))[0]?:$user['full_name'];
$pendingTasks=array_values(array_filter($assignments,static fn($a)=>($a['status']??'pending')!=='completed'));
$doneTasks=array_values(array_filter($assignments,static fn($a)=>($a['status']??'')==='completed'));
$pendingCount=count($pendingTasks);
$pendingDates=array_values(array_unique(array_column($pendingTasks,'work_date'))); sort($pendingDates);
if (!$pendingCount) $heroLine='You’re all caught up — no pending tasks.';
elseif (count($pendingDates)===1) $heroLine=$pendingCount.($pendingCount===1?' task is waiting — assigned on ':' tasks are waiting — all assigned on ').date('j M',strtotime($pendingDates[0])).'.';
else $heroLine=$pendingCount.' tasks are waiting — oldest from '.date('j M',strtotime($pendingDates[0])).'.';
$taskList=array_slice(array_merge($pendingTasks,$doneTasks),0,5);
$taskTypes=['reel'=>'Reel','poster'=>'Poster','carousel'=>'Carousel','thumbnail'=>'Thumbnail','video_editing'=>'Video Editing','motion_graphics'=>'Motion Graphics','image_editing'=>'Image Editing','other'=>'Other'];
$taskIsVideo=static fn(array $a)=>in_array((string)($a['editing_type']??''),['reel','video_editing','motion_graphics'],true);
$taskDue=static function(array $a) use ($todayYmd): array {
    if (($a['status']??'')==='completed') return ['Completed','is-done'];
    $days=(int)((strtotime($todayYmd)-strtotime((string)$a['work_date']))/86400);
    if ($days>0) return ['Overdue · '.$days.' '.($days===1?'day':'days'),'is-late'];
    if ($days===0) return ['Due today','is-today'];
    return ['Due '.date('j M',strtotime((string)$a['work_date'])),'is-later'];
};
$submittedDays=array_filter($workTrend,static fn($d)=>$d['submitted']);
$weekStart=date('j',strtotime($workTrend[0]['date'])); $weekEnd=date('j M',strtotime($workTrend[6]['date']));
if (!$submittedDays) $weekLine='No work submitted between '.$weekStart.' and '.$weekEnd.'. Submit your daily work so your manager can see your progress here.';
else { $weekTasks=array_sum(array_column($submittedDays,'task_count')); $weekDone=array_sum(array_column($submittedDays,'completed_count')); $weekLine='Submitted on '.count($submittedDays).' of 7 days · '.$weekTasks.' '.($weekTasks===1?'task':'tasks').', '.$weekDone.' completed.'; }

?>
<div class="ed-page">

<header class="ed-head">
    <div>
        <p class="ed-crumb"><?= h($user['department_name']) ?> · <?= h(date('l, j F Y')) ?></p>
        <h1 class="ed-title"><?= h($greeting) ?>, <?= h($firstName) ?></h1>
    </div>
    <details class="ed-bell">
        <summary aria-label="Notifications"><?= icon('bell') ?><?php if ($unreadNotifications): ?><b class="ed-bell-count"><?= $unreadNotifications>9?'9+':$unreadNotifications ?></b><?php endif; ?></summary>
        <section class="ed-bell-pop employee-notifications" id="employee-notifications">
            <div class="ed-bell-head"><strong>Notifications · <span data-notification-count><?= count($dashboardNotifications) ?></span></strong><a href="manager-notification.php">View all</a></div>
            <?php if (!$dashboardNotifications): ?>
                <p class="muted">No recent notifications. New messages stay here for 24 hours.</p>
            <?php else: ?>
                <div class="employee-notification-feed" role="region" aria-label="Recent notifications">
                    <?php foreach ($dashboardNotifications as $notification): ?>
                        <a class="employee-notification-card<?= $notification['read_at']?'':' unread' ?>" id="notification-<?= (int)$notification['id'] ?>" data-expires-at="<?= h(date(DATE_ATOM,strtotime($notification['created_at'])+86400)) ?>" href="notification-open.php?id=<?= (int)$notification['id'] ?>">
                            <strong><?= h($notification['title']) ?></strong>
                            <p><?= h($notification['message']) ?></p>
                            <small><?= h($notification['sender_name']?:'Bhavi team') ?> · <?= h($user['full_name']) ?> · <?= h(date('d M, g:i A',strtotime($notification['created_at']))) ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </details>
</header>

<section class="ed-hero">
    <div class="ed-hero-main">
        <p class="ed-hero-kicker">Your day</p>
        <h2 class="ed-hero-title"><?= h($heroLine) ?></h2>
        <div class="ed-hero-stats">
            <div><strong><?= $todayAttendance?h(date('g:i A',strtotime($todayAttendance['login_at']))):'—' ?></strong><span><?= $todayAttendance?'Logged in today':'Not logged in yet' ?></span></div>
            <div><strong class="is-gold"><?= $pendingCount ?></strong><span>Pending tasks</span></div>
            <div><strong><?= count($entries) ?></strong><span>Work entries today</span></div>
        </div>
    </div>
    <a class="ed-hero-btn" href="employee-daily-work.php">＋ Update today’s work</a>
</section>

<div class="ed-grid">
    <section class="ed-card ed-tasks">
        <div class="ed-card-head"><h3>My tasks</h3><a href="employee-assigned-work.php">All assigned work →</a></div>
        <?php if (!$taskList): ?>
            <div class="ed-empty"><strong>No tasks assigned right now</strong><span>New work from your manager will show up here.</span></div>
        <?php endif; ?>
        <?php foreach ($taskList as $task): [$dueLabel,$dueClass]=$taskDue($task); $video=$taskIsVideo($task); $type=$taskTypes[(string)($task['editing_type']??'')]??''; $ref=(string)($task['reference_link']??''); $refOk=$ref!=='' && preg_match('~^https?://~i',$ref); ?>
            <article class="ed-task <?= $dueClass ?>">
                <span class="ed-task-icon<?= $video?' is-video':'' ?>"><?= icon($video?'video':'document') ?></span>
                <div class="ed-task-text">
                    <strong><?= h($task['title']) ?><?php if (in_array($task['priority'] ?? 'normal', ['urgent', 'high'], true) && $dueClass !== 'is-done'): ?> <span class="aw-priority is-<?= h($task['priority']) ?>"><?= h(assignment_priority_label($task['priority'])) ?></span><?php endif; ?></strong>
                    <small><?= h(implode(' · ',array_filter([$task['client_name']??'',$type]))) ?><?php if ($refOk): ?> · <a href="<?= h($ref) ?>" target="_blank" rel="noopener noreferrer">Reference ↗</a><?php endif; ?></small>
                </div>
                <span class="ed-chip <?= $dueClass ?>"><?= h($dueLabel) ?></span>
                <?php if ($dueClass!=='is-done'): ?><a class="ed-task-btn" href="employee-assigned-work.php?task=<?= (int) $task['id'] ?>">Open task</a><?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (count($assignments)>count($taskList)): ?><a class="ed-more" href="employee-assigned-work.php">+ <?= count($assignments)-count($taskList) ?> more tasks</a><?php endif; ?>
    </section>

    <aside class="ed-side">
        <a class="ed-tile" href="employee-attendance.php" data-attendance-card>
            <span class="ed-tile-icon is-green"><?= icon('clock') ?></span>
            <span class="ed-tile-text">
                <h2>Attendance</h2>
                <strong><?= $todayAttendance?'In at '.h(date('g:i A',strtotime($todayAttendance['login_at']))):'Not logged in' ?></strong>                <p><?= $todayAttendance?'View day-wise attendance':'Use Login in the sidebar to record today’s time' ?></p>
                <?php $edTiming=employee_timing(one('SELECT shift_start,shift_end,shift_label,shift_slots,shift_hours FROM employee_profiles WHERE user_id=?',[$user['id']])?:[]); ?><p class="ed-timing">Your timing · <?= h($edTiming['text']) ?><?= $edTiming['custom']?' ('.h($edTiming['label']).')':'' ?></p>
                <?php if ($todayAttendance && $todayAttendance['logout_at']): ?><p data-attendance-logout>Logout time · <?= h(date('g:i A',strtotime($todayAttendance['logout_at']))) ?></p><?php $edReview=one("SELECT kind,status,worked_seconds FROM attendance_reviews WHERE employee_id=? AND work_date=? AND kind='overtime'",[$user['id'],today()]); if ($edReview && $edReview['status']==='pending'): ?><p class="ed-timing">Extra <?= h(work_duration_label((int)$edReview['worked_seconds']-(int)round(duty_hours((int)$user['id'])*3600))) ?> · waiting for Managing Director approval</p><?php endif; ?><?php endif; ?>
            </span>
            <span class="ed-tile-arrow">→</span>
        </a>
        <a class="ed-tile" href="apply-leaves.php#leave-requests">
            <span class="ed-tile-icon is-gold"><?= icon('calendar') ?></span>
            <span class="ed-tile-text">
                <h2>Leave</h2>
                <strong><?= h($latestLeave?ucfirst($latestLeave['status']):'No requests') ?></strong>
                <p><?= $latestLeave?h(date('j M Y',strtotime($latestLeave['from_date']))):'Apply for leave' ?></p>
            </span>
            <span class="ed-tile-arrow">→</span>
        </a>
        <a class="ed-tile" href="admin-holidays.php">
            <span class="ed-tile-icon is-green"><?= icon('briefcase') ?></span>
            <span class="ed-tile-text">
                <h2>Next holiday</h2>
                <strong><?= h($nextHoliday?date('j M',strtotime($nextHoliday['holiday_date'])):'None scheduled') ?></strong>
                <p><?= h($nextHoliday['holiday_name']??'View holiday calendar') ?></p>
            </span>
            <span class="ed-tile-arrow">→</span>
        </a>
        <?php
        // This month's salary and the admin's latest add / cut decisions with remarks.
        $edSalaryEmployee = salary_employee((int) $user['id']);
        $edMonth = $edSalaryEmployee ? salary_month($edSalaryEmployee, date('Y-m')) : null;
        // Latest 3: approval decisions (extra work / missed logout) and amounts the admin added or cut by hand.
        try {
            $edDecisions = rows("SELECT work_date,CAST(kind AS CHAR) AS kind,CAST(status AS CHAR) AS status,amount,remark,COALESCE(reviewed_at,created_at) AS at FROM attendance_reviews WHERE employee_id=?
                UNION ALL SELECT adjustment_date,CAST(kind AS CHAR),'approved',amount,reason,created_at FROM salary_adjustments WHERE employee_id=?
                ORDER BY at DESC LIMIT 3", [$user['id'], $user['id']]);
        } catch (Throwable $e) { $edDecisions = []; }
        $edAdded = $edMonth ? $edMonth['overtime_add'] + $edMonth['manual_add'] : 0;
        $edCut = $edMonth ? $edMonth['missed_cut'] + $edMonth['manual_cut'] : 0;
        ?>
        <a class="ed-tile ed-salary-tile" href="employee-salary.php">
            <span class="ed-tile-icon is-gold"><?= icon('money') ?></span>
            <span class="ed-tile-text">
                <h2>My salary · <?= h(date('F')) ?></h2>
                <strong><?= $edMonth && $edMonth['salary'] ? h(paise_label($edMonth['net'])) : 'No salary set' ?></strong>
                <?php if ($edMonth && $edMonth['salary']): ?><p><?= $edMonth['present'] ?> present · <?= $edMonth['leave'] ?> leave<?= $edAdded ? ' · +' . h(paise_label($edAdded)) . ' added' : '' ?><?= $edCut ? ' · −' . h(paise_label($edCut)) . ' cut' : '' ?></p><?php endif; ?>
                <?php foreach ($edDecisions as $edD): $edAmt = money_cents((string) $edD['amount']); $edOt = in_array($edD['kind'], ['overtime', 'add'], true); ?>
                    <span class="ed-decision is-<?= h($edD['status']==='approved' && $edAmt>0 ? ($edOt?'add':'cut') : $edD['status']) ?>"><b><?= h(date('d M', strtotime($edD['work_date']))) ?></b> <?php if ($edD['status'] === 'pending'): ?><?= $edOt ? 'Extra work' : 'Missed logout' ?> · waiting for Managing Director<?php elseif ($edD['status'] === 'approved' && $edAmt > 0): ?><em class="<?= $edOt ? 'is-pos' : 'is-neg' ?>">Managing Director <?= $edOt ? 'added +' : 'cut −' ?><?= h(paise_label($edAmt)) ?></em><?php else: ?>Managing Director: <?= $edOt ? 'extra not approved' : 'no cut' ?><?php endif; ?><?php if ($edD['remark'] !== ''): ?> — “<?= h($edD['remark']) ?>”<?php endif; ?></span>
                <?php endforeach; ?>
            </span>
            <span class="ed-tile-arrow">→</span>
        </a>
        <a class="ed-tile is-dark" href="employee-profile.php">
            <span class="ed-tile-icon"><?= icon('people') ?></span>
            <span class="ed-tile-text">
                <h2>My profile &amp; salary</h2>
                <p>Details, payslips and benefits</p>
            </span>
            <span class="ed-tile-arrow">→</span>
        </a>
    </aside>
</div>

<section class="ed-card ed-week">
    <div class="ed-week-text">
        <h3>Last 7 days</h3>
        <p><?= h($weekLine) ?></p>
    </div>
    <div class="ed-week-days" role="list" aria-label="Daily work submitted in the last seven days">
        <?php foreach ($workTrend as $day): $isToday=$day['date']===$todayYmd; $isSunday=date('w',strtotime($day['date']))==='0'; $cls=$day['submitted']?'is-done':($isSunday?'is-off':''); ?>
            <div class="ed-day<?= $cls?' '.$cls:'' ?><?= $isToday?' is-today':'' ?>" role="listitem" title="<?= h(date('l, j M',strtotime($day['date']))) ?> · <?= $day['submitted']?h($day['completed_count'].'/'.$day['task_count'].' tasks done'):($isSunday?'Weekly off':'Not submitted') ?>">
                <span class="ed-day-box"><?php if ($day['submitted']): ?><b><?= $day['task_count'] ?></b><?php endif; ?></span>
                <small><?= $isToday?'Today':h(date('D j',strtotime($day['date']))) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</section>

</div>
<script>
document.addEventListener('click',e=>{const bell=document.querySelector('.ed-bell[open]');if(bell&&!bell.contains(e.target))bell.removeAttribute('open');});
// Opening the bell marks notifications as read: the number on the bell and in the sidebar disappears.
(()=>{const bell=document.querySelector('.ed-bell');if(!bell)return;bell.addEventListener('toggle',()=>{if(!bell.open)return;const count=bell.querySelector('.ed-bell-count');if(!count)return;count.remove();document.querySelectorAll('.portal-sidebar a[href="manager-notification.php"] .nav-badge').forEach(b=>b.remove());bell.querySelectorAll('.employee-notification-card.unread').forEach(c=>c.classList.remove('unread'));fetch('notifications-mark-read.php',{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf:<?= json_encode(csrf_token()) ?>})}).catch(()=>{});});})();
</script>

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
    &&
    $employeeView !==
    'daily'
    &&
    $employeeView !==
    'history'
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

.employee-assignment-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px 18px;
    margin: 16px 0;
}

.employee-assignment-meta dt {
    font-size: 12px;
    opacity: .7;
}

.employee-assignment-meta dd {
    margin: 3px 0 0;
    font-weight: 600;
    overflow-wrap: anywhere;
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

<?php
// ---------- My assigned work: list (left) + selected task (right) ----------
$awTasks = rows("SELECT a.*, c.client_name, c.logo_path AS client_logo, u.full_name AS assigned_by_name,
        a.timer_accumulated_seconds + IF(a.timer_resumed_at IS NULL, 0, GREATEST(0, TIMESTAMPDIFF(SECOND, a.timer_resumed_at, NOW()))) AS timer_elapsed
    FROM work_assignments a
    LEFT JOIN clients c ON c.id=a.client_id
    JOIN users u ON u.id=a.assigned_by
    WHERE a.employee_id=? AND a.deleted_at IS NULL
      AND (a.status<>'completed' OR a.work_date>=DATE_SUB(CURDATE(), INTERVAL 30 DAY))
    ORDER BY a.work_date, FIELD(a.priority,'urgent','high','normal'), a.id", [$user['id']]);
$awTodayTs = strtotime(today());
$awGroup = static fn(array $t): string => ($t['status'] === 'completed' || !empty($t['completed_at'])) ? 'done' : (!empty($t['started_at']) ? 'progress' : 'todo');
$awCounts = ['todo' => 0, 'progress' => 0, 'done' => 0];
foreach ($awTasks as $t) $awCounts[$awGroup($t)]++;
$awOverdue = static function (string $workDate) use ($awTodayTs): int { return max(0, (int) round(($awTodayTs - strtotime($workDate)) / 86400)); };
$awTypes = ['reel' => 'Reel', 'poster' => 'Poster', 'thumbnail' => 'Thumbnail', 'carousel' => 'Carousel', 'video_editing' => 'Video Editing', 'motion_graphics' => 'Motion Graphics', 'image_editing' => 'Image Editing', 'other' => 'Other'];
$awTypeLabel = static fn(?string $type): string => $awTypes[(string) $type] ?? ($type ? ucwords(str_replace('_', ' ', $type)) : '');

// Which task is open on the right
$awSelected = null;
$awRequested = (int) ($_GET['task'] ?? 0);
foreach ($awTasks as $t) if ((int) $t['id'] === $awRequested) $awSelected = $t;
if (!$awSelected && $awRequested) {
    // A task older than the list window (e.g. from a notification) can still be opened directly.
    $awSelected = one("SELECT a.*, c.client_name, c.logo_path AS client_logo, u.full_name AS assigned_by_name, a.timer_accumulated_seconds + IF(a.timer_resumed_at IS NULL,0,GREATEST(0,TIMESTAMPDIFF(SECOND,a.timer_resumed_at,NOW()))) AS timer_elapsed FROM work_assignments a LEFT JOIN clients c ON c.id=a.client_id JOIN users u ON u.id=a.assigned_by WHERE a.id=? AND a.employee_id=? AND a.deleted_at IS NULL", [$awRequested, $user['id']]);
    if ($awSelected) $awTasks[] = $awSelected;
}
if (!$awSelected && $assignmentDate) {
    foreach ($awTasks as $t) if ($t['work_date'] === $assignmentDate && $awGroup($t) !== 'done') { $awSelected = $t; break; }
    if (!$awSelected) foreach ($awTasks as $t) if ($t['work_date'] === $assignmentDate) { $awSelected = $t; break; }
}
if (!$awSelected) foreach (['progress', 'todo', 'done'] as $g) { foreach ($awTasks as $t) if ($awGroup($t) === $g) { $awSelected = $t; break 2; } }
$awTab = $awSelected ? $awGroup($awSelected) : 'todo';
$awPosition = 0;
foreach ($awTasks as $i => $t) if ($awSelected && (int) $t['id'] === (int) $awSelected['id']) $awPosition = $i + 1;

// Task brief: plain text written by the manager, shown with headings, bullets and an "important" box.
$awBrief = static function (?string $text): string {
    $lines = preg_split('/\R/u', trim((string) $text)) ?: [];
    $html = ''; $list = false; $box = false; $intro = true; $para = [];
    $flushPara = static function () use (&$para, &$html, &$intro): void {
        if (!$para) return;
        $body = nl2br(h(implode("\n", $para)));
        $html .= $intro ? '<p class="aw-brief-intro">' . $body . '</p>' : '<p>' . $body . '</p>';
        $para = []; $intro = false;
    };
    $closeList = static function () use (&$list, &$html): void { if ($list) { $html .= '</ul>'; $list = false; } };
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') { $flushPara(); $closeList(); continue; }
        $plain = rtrim($line, ':');
        $letters = preg_replace('/[^\p{L}]/u', '', $plain);
        $isHeading = mb_strlen($plain) <= 70 && $letters !== '' && (mb_strtoupper($letters) === $letters && mb_strlen($letters) >= 3 || (str_ends_with($line, ':') && !preg_match('/^[-•*✓▪●]|^\d+[.)]/u', $line) && mb_strlen($plain) <= 40));
        if ($isHeading) {
            $flushPara(); $closeList();
            if ($box) { $html .= '</div>'; $box = false; }
            if (preg_match('/important|do not|don\'t|note|warning/i', $plain)) { $html .= '<div class="aw-brief-important"><h4>' . h($plain) . '</h4>'; $box = true; }
            else $html .= '<h4>' . h($plain) . '</h4>';
            $intro = false;
            continue;
        }
        if (preg_match('/^(?:[-•*✓▪●]|\d+[.)])\s*(.+)$/u', $line, $m)) {
            $flushPara();
            if (!$list) { $html .= '<ul>'; $list = true; }
            $html .= '<li>' . h($m[1]) . '</li>';
            $intro = false;
            continue;
        }
        $closeList();
        $para[] = $line;
    }
    $flushPara(); $closeList();
    if ($box) $html .= '</div>';
    return $html !== '' ? $html : '<p class="muted">No brief added for this task.</p>';
};
?>
<div class="aw-page">
    <header class="aw-head">
        <div>
            <p class="aw-crumb">Dashboard / My assigned work</p>
            <h1 class="aw-title">My assigned work</h1>
            <p class="aw-sub"><?= $awCounts['todo'] + $awCounts['progress'] ?> <?= $awCounts['todo'] + $awCounts['progress'] === 1 ? 'task' : 'tasks' ?> from your manager · <a href="#client-requests">Client requests for <?= h($user['department_name']) ?>: <?= count($departmentRequirements) ?></a></p>
        </div>
        <div class="aw-head-actions">
            <div class="aw-tabs" role="tablist">
                <?php foreach (['todo' => 'To do', 'progress' => 'In progress', 'done' => 'Done'] as $key => $label): ?>
                    <button type="button" role="tab" data-aw-tab="<?= $key ?>" class="<?= $awTab === $key ? 'active' : '' ?>"><?= $label ?> · <?= $awCounts[$key] ?></button>
                <?php endforeach; ?>
            </div>
            <a class="aw-pdf" href="download-assignments.php<?= $assignmentDate ? '?assignment_date=' . h($assignmentDate) : '' ?>">Download PDF</a>
        </div>
    </header>

    <?php if (!$awTasks): ?>
        <div class="aw-empty-all"><span><?= icon('tasks') ?></span><h2>No assigned work yet</h2><p>When your manager assigns a task it will appear here with its full brief.</p></div>
    <?php else: ?>
    <div class="aw-layout" id="assigned-work">
        <aside class="aw-list" data-aw-list>
            <?php $awLastDate = null; $awNum = 0; foreach ($awTasks as $t): $awNum++; $g = $awGroup($t); $late = $g !== 'done' ? $awOverdue($t['work_date']) : 0;
                if ($t['work_date'] !== $awLastDate): $awLastDate = $t['work_date']; ?>
                <a class="aw-date<?= $late ? ' is-late' : '' ?>" data-aw-date="<?= h($t['work_date']) ?>" href="employee-assigned-work.php?assignment_date=<?= h($t['work_date']) ?>"><span><?= h(date('D, j M Y', strtotime($t['work_date']))) ?></span><?php if ($late): ?><b>Overdue · <?= $late ?> <?= $late === 1 ? 'day' : 'days' ?></b><?php elseif ($t['work_date'] === today()): ?><b class="is-today">Today</b><?php endif; ?></a>
            <?php endif; ?>
                <a class="aw-item<?= $awSelected && (int) $awSelected['id'] === (int) $t['id'] ? ' active' : '' ?>" data-aw-group="<?= $g ?>" data-aw-item-date="<?= h($t['work_date']) ?>" href="employee-assigned-work.php?task=<?= (int) $t['id'] ?>">
                    <span class="aw-num"><?= $g === 'done' ? '✓' : $awNum ?></span>
                    <span class="aw-item-text">
                        <strong><?= h($t['title']) ?></strong>
                        <?php if (in_array($t['priority'] ?? 'normal', ['urgent', 'high'], true) && $g !== 'done'): ?><span class="aw-priority is-<?= h($t['priority']) ?>"><?= h(assignment_priority_label($t['priority'])) ?></span><?php endif; ?>
                        <small><?= h(implode(' · ', array_filter([$t['client_name'] ?: 'Internal work', $awTypeLabel($t['editing_type'] ?? null)]))) ?></small>
                        <em class="aw-state is-<?= $g ?>"><?= $g === 'done' ? 'Done' : ($g === 'progress' ? (empty($t['timer_resumed_at']) ? 'Paused' : 'Working') : 'Not started') ?></em>
                    </span>
                </a>
            <?php endforeach; ?>
            <p class="aw-list-empty" data-aw-list-empty hidden>No tasks here.</p>
        </aside>

        <?php if ($awSelected):
            $sel = $awSelected; $g = $awGroup($sel); $late = $g !== 'done' ? $awOverdue($sel['work_date']) : 0;
            $running = $g === 'progress' && !empty($sel['timer_resumed_at']);
            $elapsed = $g === 'done' ? (int) ($sel['time_spent_seconds'] ?? ((int) $sel['time_spent_minutes'] * 60)) : (int) $sel['timer_elapsed'];
            $ref = trim((string) ($sel['reference_link'] ?? ''));
            $files = rows('SELECT id,original_name,mime_type,file_size FROM work_assignment_attachments WHERE assignment_id=? ORDER BY id', [$sel['id']]);
        ?>
        <div class="aw-detail">
            <section class="aw-card aw-summary">
                <div class="aw-summary-top">
                    <div>
                        <p class="aw-kicker">Task <?= $awPosition ?> of <?= count($awTasks) ?></p>
                        <h2 class="aw-task-title"><?= h($sel['title']) ?></h2>
                        <?php if (in_array($sel['priority'] ?? 'normal', ['urgent', 'high'], true) && $g !== 'done'): ?><span class="aw-priority is-<?= h($sel['priority']) ?> is-big"><?= $sel['priority'] === 'urgent' ? '⚠ ' : '' ?><?= h(assignment_priority_label($sel['priority'])) ?></span><?php endif; ?>
                    </div>
                    <?php if ($g === 'done'): ?><span class="aw-chip is-done">Completed</span><?php elseif ($late): ?><span class="aw-chip is-late">Overdue · <?= $late ?> <?= $late === 1 ? 'day' : 'days' ?></span><?php elseif ($sel['work_date'] === today()): ?><span class="aw-chip is-today">Due today</span><?php else: ?><span class="aw-chip">Due <?= h(date('j M', strtotime($sel['work_date']))) ?></span><?php endif; ?>
                </div>
                <dl class="aw-meta">
                    <div><dt>Client</dt><dd><?= h($sel['client_name'] ?: 'Internal work') ?></dd></div>
                    <div><dt>Type</dt><dd><?= h($awTypeLabel($sel['editing_type'] ?? null) ?: 'Not specified') ?></dd></div>
                    <div><dt>Work date</dt><dd><?= h(date('j M Y', strtotime($sel['work_date']))) ?></dd></div>
                    <div><dt>Priority</dt><dd class="aw-priority-text is-<?= h($sel['priority'] ?? 'normal') ?>"><?= h(assignment_priority_label($sel['priority'] ?? null)) ?></dd></div>
                </dl>
            </section>

            <div class="aw-detail-grid">
                <section class="aw-card aw-brief">
                    <h3>Task brief</h3>
                    <div class="aw-brief-body"><?= $awBrief($sel['description'] ?? '') ?></div>
                    <?php if ($g === 'done' && !empty($sel['employee_remark'])): ?>
                        <div class="aw-remark"><h4>Your remark</h4><p><?= nl2br(h($sel['employee_remark'])) ?></p></div>
                    <?php endif; ?>
                </section>

                <aside class="aw-side">
                    <section class="aw-timer<?= $running ? ' is-running' : '' ?>" data-aw-timer data-elapsed="<?= $elapsed ?>" data-running="<?= $running ? '1' : '0' ?>">
                        <p class="aw-timer-label">Work timer · <?= $g === 'done' ? 'Finished' : ($running ? 'Running' : ($g === 'progress' ? 'Paused' : 'Not started')) ?></p>
                        <strong class="aw-clock" data-aw-clock><?= sprintf('%02d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60) ?></strong>
                        <?php if ($g !== 'done'): ?>
                            <form method="post"><?= csrf_field() ?>
                                <input type="hidden" name="assignment_id" value="<?= (int) $sel['id'] ?>">
                                <?php if ($running): ?>
                                    <input type="hidden" name="action" value="pause_assignment"><button class="aw-timer-btn is-pause" type="submit">❚❚ Pause</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="start_assignment"><button class="aw-timer-btn" type="submit">▶ <?= $g === 'progress' ? 'Resume work' : 'Start work' ?></button>
                                <?php endif; ?>
                            </form>
                            <p class="aw-timer-note">Pause anytime. Time is added to your daily work automatically when you submit.</p>
                        <?php else: ?>
                            <p class="aw-timer-note"><?= !empty($sel['started_at']) ? 'Started ' . h(date('j M, g:i A', strtotime($sel['started_at']))) : '' ?><?= !empty($sel['completed_at']) ? ' · Finished ' . h(date('j M, g:i A', strtotime($sel['completed_at']))) : '' ?></p>
                        <?php endif; ?>
                    </section>

                    <section class="aw-card aw-files">
                        <h3>Reference &amp; files</h3>
                        <?php if ($ref !== '' && preg_match('~^https?://~i', $ref)): ?>
                            <a class="aw-file" href="<?= h($ref) ?>" target="_blank" rel="noopener noreferrer"><span class="aw-file-icon">🔗</span><span>Open reference link ↗</span></a>
                        <?php else: ?>
                            <span class="aw-file is-empty"><span class="aw-file-icon">🔗</span><span>No reference link</span></span>
                        <?php endif; ?>
                        <?php if ($files): foreach ($files as $file): $mime = strtolower((string) $file['mime_type']); $fileIcon = str_starts_with($mime, 'image/') ? '🖼️' : (str_starts_with($mime, 'video/') ? '🎬' : ($mime === 'application/pdf' ? '📄' : '📎')); ?>
                            <div class="aw-file"><span class="aw-file-icon"><?= $fileIcon ?></span><span class="aw-file-name"><?= h($file['original_name']) ?></span><a href="assignment-file.php?id=<?= (int) $file['id'] ?>&amp;mode=view" target="_blank" rel="noopener noreferrer">Open</a><a href="assignment-file.php?id=<?= (int) $file['id'] ?>&amp;mode=download">Save</a></div>
                        <?php endforeach; else: ?>
                            <span class="aw-file is-empty"><span class="aw-file-icon">📄</span><span>No attached files</span></span>
                        <?php endif; ?>
                        <?php if (!empty($sel['client_logo']) && $sel['client_id']): ?>
                            <a class="aw-outline-btn" href="client-logo.php?id=<?= (int) $sel['client_id'] ?>&amp;download=1">Get client logo</a>
                        <?php else: ?>
                            <span class="aw-outline-btn is-disabled">No client logo</span>
                        <?php endif; ?>
                    </section>

                    <section class="aw-card aw-finish">
                        <h3><?= $g === 'done' ? 'Submitted' : (!empty($sel['started_at']) ? 'Finished this task?' : 'Step 1: click Start work') ?></h3>
                        <?php if ($g === 'done'): ?>
                            <p class="aw-done-note">✓ Sent to your manager<?= $elapsed ? ' · ' . h(employee_assignment_duration($elapsed)) : '' ?></p>
                        <?php elseif (!empty($sel['started_at'])): ?>
                            <form method="post" class="aw-finish-form"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="end_assignment"><input type="hidden" name="assignment_id" value="<?= (int) $sel['id'] ?>">
                                <label class="sr-only" for="aw-remark">Remark</label>
                                <textarea id="aw-remark" name="remark" maxlength="5000" placeholder="Output link, what you finished, or anything your manager should know"><?= h($sel['employee_remark'] ?? '') ?></textarea>
                                <button class="aw-primary-btn" type="submit">✓ Complete &amp; submit</button>
                            </form>
                            <p class="aw-hint">After you submit, your next task opens automatically.</p>
                        <?php else: ?>
                            <p class="aw-hint">Click <b>▶ Start work</b> when you begin. When the work is done, click <b>✓ Complete &amp; submit</b> here.</p>
                            <a class="aw-outline-btn" href="employee-daily-work.php?assignment=<?= (int) $sel['id'] ?>#add-work">Already done without the timer? Submit with your own time</a>
                        <?php endif; ?>
                        <a class="aw-outline-btn" href="chat.php">Ask manager a question</a>
                    </section>
                </aside>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <section class="aw-card aw-requests" id="client-requests">
        <div class="aw-requests-head"><h3>Client requests for <?= h($user['department_name']) ?></h3><span class="aw-chip"><?= count($departmentRequirements) ?> requests</span></div>
        <?php if (!$departmentRequirements): ?><p class="muted">No client requests for your department yet.</p><?php endif; ?>
        <?php foreach ($departmentRequirements as $request): $requestFiles = rows('SELECT id,original_file_name FROM requirement_files WHERE requirement_id=? ORDER BY id', [$request['id']]); ?>
            <article class="requirement-card aw-request">
                <div class="aw-request-top"><div><strong><?= h($request['client_name']) ?></strong><small>Due <?= $request['due_date'] ? h(date('d-m-Y', strtotime($request['due_date']))) : 'No deadline' ?></small></div><?= status_badge($request['status']) ?></div>
                <p class="requirement-remark"><?= nl2br(h($request['brief'])) ?></p>
                <?php if ($request['approved_text']): ?><p class="requirement-remark"><strong>Team update:</strong><br><?= nl2br(h($request['approved_text'])) ?></p><?php endif; ?>
                <?php if ($requestFiles): ?><ul class="requirement-file-list"><?php foreach ($requestFiles as $requestFile): ?><li><a href="download-requirement-file.php?id=<?= (int) $requestFile['id'] ?>"><?= h($requestFile['original_file_name']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<script>
(() => {
    // Tabs filter the task list (To do / In progress / Done)
    const list = document.querySelector('[data-aw-list]');
    if (list) {
        const empty = list.querySelector('[data-aw-list-empty]');
        const show = tab => {
            let any = false;
            list.querySelectorAll('.aw-item').forEach(i => { const on = i.dataset.awGroup === tab; i.hidden = !on; if (on) any = true; });
            list.querySelectorAll('.aw-date').forEach(d => { d.hidden = !list.querySelector(`.aw-item[data-aw-item-date="${d.dataset.awDate}"]:not([hidden])`); });
            empty.hidden = any;
            document.querySelectorAll('[data-aw-tab]').forEach(b => b.classList.toggle('active', b.dataset.awTab === tab));
        };
        document.querySelectorAll('[data-aw-tab]').forEach(b => b.addEventListener('click', () => show(b.dataset.awTab)));
        show(document.querySelector('[data-aw-tab].active')?.dataset.awTab || 'todo');
    }
    // Live timer: counts from the server's elapsed seconds, so pauses and page reloads keep the right total.
    const timer = document.querySelector('[data-aw-timer]');
    if (timer && timer.dataset.running === '1') {
        const clock = timer.querySelector('[data-aw-clock]'), base = Number(timer.dataset.elapsed) || 0, loaded = Date.now();
        const pad = n => String(n).padStart(2, '0');
        const tick = () => { const s = base + Math.floor((Date.now() - loaded) / 1000); clock.textContent = `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`; };
        tick(); setInterval(tick, 1000);
    }
})();
</script>


<?php endif; ?>



<?php
/* =========================================================
   HISTORY
========================================================= */

if ($employeeView === 'history'):

// ---------- My submitted work: day / week / month sheet ----------
$swRange = in_array($_GET['range'] ?? '', ['day', 'week', 'month'], true) ? $_GET['range'] : 'week';
$swToday = today();
$swStripEnd = min($swToday, date('Y-m-d', strtotime($date . ' +3 days')));
if ($swStripEnd < $date) $swStripEnd = $date;
$swStripStart = date('Y-m-d', strtotime($swStripEnd . ' -6 days'));
if ($swRange === 'day') { $swFrom = $date; $swTo = $date; }
elseif ($swRange === 'month') { $swFrom = date('Y-m-01', strtotime($date)); $swTo = min(date('Y-m-t', strtotime($date)), $swToday); }
else { $swFrom = $swStripStart; $swTo = $swStripEnd; }
$swQuery = static fn(array $extra = []): string => 'employee-work-history.php?' . http_build_query(array_merge(['date' => $date, 'range' => $swRange], $extra));

$swDayCounts = [];
foreach (rows('SELECT s.work_date, COUNT(e.id) AS n FROM daily_work_submissions s LEFT JOIN daily_work_entries e ON e.submission_id=s.id WHERE s.employee_id=? AND s.work_date BETWEEN ? AND ? GROUP BY s.work_date', [$user['id'], $swStripStart, $swStripEnd]) as $row) $swDayCounts[$row['work_date']] = (int) $row['n'];

$swEntries = rows('SELECT e.*, s.work_date, s.submission_status, c.client_name FROM daily_work_entries e JOIN daily_work_submissions s ON s.id=e.submission_id LEFT JOIN clients c ON c.id=e.client_id WHERE s.employee_id=? AND s.work_date BETWEEN ? AND ? ORDER BY s.work_date DESC, e.row_order', [$user['id'], $swFrom, $swTo]);
$swSheets = rows('SELECT work_date, submission_status, manager_remark, review_status FROM daily_work_submissions WHERE employee_id=? AND work_date BETWEEN ? AND ? ORDER BY work_date DESC', [$user['id'], $swFrom, $swTo]);
$swMinutes = array_sum(array_map(static fn($e) => (int) ($e['time_spent_minutes'] ?? 0), $swEntries));
$swCompleted = count(array_filter($swEntries, static fn($e) => ($e['task_status'] ?? '') === 'completed'));
$swPending = rows("SELECT a.id, a.title, a.work_date, c.client_name FROM work_assignments a LEFT JOIN clients c ON c.id=a.client_id WHERE a.employee_id=? AND a.deleted_at IS NULL AND a.status<>'completed' AND NOT EXISTS (SELECT 1 FROM daily_work_entries e WHERE e.assignment_id=a.id) ORDER BY a.work_date, a.id", [$user['id']]);
$swHours = static fn(int $m): string => ($m > 0 && $m < 60) ? $m . 'm' : intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '');
$swRangeLabel = $swRange === 'day' ? ($date === $swToday ? 'today' : date('j M', strtotime($date))) : ($swRange === 'week' ? 'this week' : 'in ' . date('M', strtotime($date)));
$swSheetTitle = $swRange === 'day' ? date('D, j M Y', strtotime($date)) : ($swRange === 'week' ? date('j', strtotime($swFrom)) . ' – ' . date('j M Y', strtotime($swTo)) : date('F Y', strtotime($date)));
$swDraftDay = null;
foreach ($swSheets as $sheetRow) if ($sheetRow['submission_status'] !== 'submitted') { $swDraftDay = $sheetRow['work_date']; break; }
$swByDate = [];
foreach ($swEntries as $e) $swByDate[$e['work_date']][] = $e;
?>
<div class="sw-page">
    <header class="sw-head">
        <div>
            <p class="sw-crumb">Dashboard / My submitted work</p>
            <h1 class="sw-title">My submitted work</h1>
            <p class="sw-sub">Your daily work sheet — what your manager sees.</p>
        </div>
        <div class="sw-head-actions">
            <nav class="sw-seg" aria-label="Sheet range">
                <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label): ?>
                    <a href="<?= h($swQuery(['range' => $key])) ?>" class="<?= $swRange === $key ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <a class="sw-download" href="download-work.php?<?= h(http_build_query(['from_date' => $swFrom, 'to_date' => $swTo])) ?>">Download sheet</a>
        </div>
    </header>

    <section class="sw-card sw-strip">
        <div class="sw-strip-row">
            <a class="sw-arrow" href="<?= h($swQuery(['date' => date('Y-m-d', strtotime($date . ' -7 days'))])) ?>" aria-label="Previous week">‹</a>
            <div class="sw-days">
                <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($swStripStart . " +$i days")); $n = $swDayCounts[$d] ?? 0; $sunday = date('w', strtotime($d)) === '0'; ?>
                    <a class="sw-day<?= $d === $date ? ' active' : '' ?><?= $sunday && !$n ? ' is-off' : '' ?><?= $n ? ' has-work' : '' ?>" href="<?= h($swQuery(['date' => $d])) ?>">
                        <small><?= $d === $swToday ? 'Today' : h(date('D j', strtotime($d))) ?></small>
                        <strong><?= $sunday && !$n ? '–' : $n ?></strong>
                    </a>
                <?php endfor; ?>
            </div>
            <?php if ($swStripEnd < $swToday): ?><a class="sw-arrow" href="<?= h($swQuery(['date' => min($swToday, date('Y-m-d', strtotime($date . ' +7 days')))])) ?>" aria-label="Next week">›</a><?php else: ?><span class="sw-arrow is-disabled" aria-hidden="true">›</span><?php endif; ?>
        </div>
        <p class="sw-strip-note">Number = entries submitted that day · <?= h(date('j', strtotime($swStripStart)) . ' – ' . date('j M Y', strtotime($swStripEnd))) ?></p>
    </section>

    <div class="sw-stats">
        <div class="sw-stat is-brand"><span>Entries <?= h($swRangeLabel) ?></span><strong><?= count($swEntries) ?></strong></div>
        <div class="sw-stat"><span>Time logged</span><strong><?= h($swHours($swMinutes)) ?></strong></div>
        <div class="sw-stat"><span>Completed</span><strong><?= $swCompleted ?></strong></div>
        <div class="sw-stat is-gold"><span>Assigned, not submitted</span><strong><?= count($swPending) ?></strong></div>
    </div>

    <div class="sw-grid">
        <section class="sw-card sw-sheet" id="daily-sheet">
            <div class="sw-sheet-head">
                <h2><?= $swRange === 'day' ? 'Daily work sheet' : 'Work sheet' ?> · <?= h($swSheetTitle) ?></h2>
                <a class="sw-add" href="employee-daily-work.php?date=<?= h($swRange === 'day' ? $date : min($swToday, $date)) ?>">+ Add entry</a>
            </div>

            <?php foreach ($swSheets as $sheetRow): if (!$sheetRow['manager_remark']) continue; ?>
                <div class="sw-feedback"><strong>Manager feedback<?= $swRange !== 'day' ? ' · ' . h(date('j M', strtotime($sheetRow['work_date']))) : '' ?>:</strong> <?= h($sheetRow['manager_remark']) ?> <?= status_badge($sheetRow['review_status']) ?></div>
            <?php endforeach; ?>

            <?php if (!$swEntries): ?>
                <div class="sw-empty">
                    <span class="sw-empty-icon"><?= icon('document') ?></span>
                    <strong>No work submitted <?= h($swRangeLabel) ?></strong>
                    <p><?= $swPending ? 'You have ' . count($swPending) . ' assigned ' . (count($swPending) === 1 ? 'task' : 'tasks') . ' — submit what you worked on before you log out.' : 'Add each piece of work you did so your manager can see it here.' ?></p>
                    <a class="sw-gold-btn" href="employee-daily-work.php?date=<?= h(min($swToday, $date)) ?>"><?= $date === $swToday ? 'Update today’s work' : 'Add work for ' . h(date('j M', strtotime($date))) ?></a>
                </div>
                <div class="sw-example" aria-hidden="true">
                    <p class="sw-example-label">Example — how a saved entry looks</p>
                    <div class="sw-example-row">
                        <span class="sw-num">1</span>
                        <div class="sw-task"><strong>Diwali reel</strong><small>Client name</small></div>
                        <span class="sw-time">1h 30m</span>
                        <span class="sw-pill is-done">Completed</span>
                    </div>
                    <div class="sw-example-foot"><span>Final reel shared on Drive</span><span class="sw-icon-btn"><?= icon('edit') ?></span><span class="sw-icon-btn">↗</span></div>
                </div>
            <?php else: ?>
                <div class="sw-table-wrap">
                    <table class="sw-table">
                        <colgroup><col class="sw-c-num"><col class="sw-c-task"><col class="sw-c-time"><col class="sw-c-status"><col class="sw-c-remark"><col class="sw-c-actions"></colgroup><thead><tr><th>#</th><th>Task &amp; client</th><th>Time</th><th>Status</th><th>Remark</th><th class="sw-right">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($swByDate as $day => $dayEntries): ?>
                            <?php if ($swRange !== 'day'): ?><tr class="sw-date-row"><td colspan="6"><?= h(date('D, j M Y', strtotime($day))) ?> · <?= count($dayEntries) ?> <?= count($dayEntries) === 1 ? 'entry' : 'entries' ?></td></tr><?php endif; ?>
                            <?php foreach ($dayEntries as $i => $entry): $typeLabel = work_type_label($entry['work_type'] ?? null); ?>
                                <tr>
                                    <td class="sw-num-cell"><?= $i + 1 ?></td>
                                    <td><div class="sw-task"><strong><?php if ($typeLabel): ?><span class="work-type-label"><?= h($typeLabel) ?></span> <?php endif; ?><?= h($entry['task_title']) ?></strong><small><?= h($entry['client_name'] ?: 'Internal work') ?></small></div></td>
                                    <td class="sw-time" title="<?= h($entry['time_spent_minutes'] === null ? '' : format_work_duration($entry['time_spent_minutes'])) ?>"><?= h($entry['time_spent_minutes'] === null ? '—' : $swHours((int) $entry['time_spent_minutes'])) ?></td>
                                    <td><span class="sw-pill <?= ($entry['task_status'] ?? '') === 'completed' ? 'is-done' : 'is-pending' ?>"><?= ($entry['task_status'] ?? '') === 'completed' ? 'Completed' : 'Pending' ?></span></td>
                                    <td class="sw-remark"><?= h($entry['remark'] ?? '') ?></td>
                                    <td class="sw-right"><div class="sw-actions">
                                        <button type="button" class="sw-icon-btn" data-dialog="sw-entry-<?= (int) $entry['id'] ?>" title="View details" aria-label="View details"><?= icon('eye') ?></button>
                                        <?php if (!$socialWork): ?><a class="sw-icon-btn" href="employee-daily-work.php?date=<?= h($entry['work_date']) ?>&amp;edit_entry=<?= (int) $entry['id'] ?>#add-work" title="Edit work" aria-label="Edit work"><?= icon('edit') ?></a><?php endif; ?>
                                        <?php if (!empty($entry['output_link'])): ?><a class="sw-icon-btn" href="<?= h($entry['output_link']) ?>" target="_blank" rel="noopener noreferrer" title="Open output" aria-label="Open output">↗</a><?php endif; ?>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($swDraftDay): ?>
                    <form method="post" action="employee-work-history.php?date=<?= h($swDraftDay) ?>" class="sw-submit-day"><?= csrf_field() ?><input type="hidden" name="action" value="submit_day"><span>Entries for <?= h(date('j M', strtotime($swDraftDay))) ?> are saved as draft.</span><button class="sw-gold-btn" type="submit">Submit day to manager</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <aside class="sw-card sw-pending">
            <h3>Assigned, not submitted yet</h3>
            <?php if (!$swPending): ?>
                <p class="sw-muted">All caught up — every assigned task has been reported.</p>
            <?php endif; ?>
            <?php foreach (array_slice($swPending, 0, 8) as $task): ?>
                <a class="sw-pending-item" href="employee-daily-work.php?assignment=<?= (int) $task['id'] ?>#add-work">
                    <span><strong><?= h($task['title']) ?></strong><small><?= h($task['client_name'] ?: 'Internal work') ?> · <?= h(date('j M', strtotime($task['work_date']))) ?></small></span>
                    <b>Submit →</b>
                </a>
            <?php endforeach; ?>
            <?php if (count($swPending) > 8): ?><a class="sw-more" href="employee-assigned-work.php">+ <?= count($swPending) - 8 ?> more in My assigned work</a><?php endif; ?>
        </aside>
    </div>
</div>
<?php foreach ($swEntries as $entry) detail_dialog('sw-entry-' . $entry['id'], 'Work details', array_merge(['Date' => date('d-m-Y', strtotime($entry['work_date'])), 'Client' => $entry['client_name'] ?: 'Internal work', 'Output link' => $entry['output_link'] ?? ''], work_details($entry, $workDepartment))); ?>

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
