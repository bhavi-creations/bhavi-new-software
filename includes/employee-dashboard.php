<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';


$user = require_roles(['employee']);

$employeeView = $employeeView ?? 'dashboard';


$viewRoutes = [
    'daily'    => 'employee-daily-work.php',
    'history'  => 'employee-work-history.php',
    'assigned' => 'employee-assigned-work.php'
];


$viewRoute =
    $viewRoutes[$employeeView]
    ?? dashboard_path($user);


if (
    isset($expectedDepartment)
    &&
    $user['department_code'] !== $expectedDepartment
) {
    redirect(
        dashboard_path($user)
    );
}


/* =========================================================
   DATABASE TIMER COLUMNS
========================================================= */

try {

    if (
        !one(
            "SHOW COLUMNS
             FROM work_assignments
             LIKE 'started_at'"
        )
    ) {

        query(
            "ALTER TABLE work_assignments
             ADD COLUMN started_at DATETIME NULL
             AFTER status"
        );
    }


    if (
        !one(
            "SHOW COLUMNS
             FROM work_assignments
             LIKE 'completed_at'"
        )
    ) {

        query(
            "ALTER TABLE work_assignments
             ADD COLUMN completed_at DATETIME NULL
             AFTER started_at"
        );
    }


    if (
        !one(
            "SHOW COLUMNS
             FROM work_assignments
             LIKE 'time_spent_seconds'"
        )
    ) {

        query(
            "ALTER TABLE work_assignments
             ADD COLUMN time_spent_seconds INT UNSIGNED NULL
             AFTER completed_at"
        );
    }

} catch (Throwable $schemaError) {

    /*
     * Hosting ALTER permission lekapothe
     * manually DB lo columns create cheyyali.
     */

}


/* =========================================================
   EXACT TIME
========================================================= */

function assignment_exact_time(
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


    $remainingSeconds =
        $seconds % 60;


    return sprintf(
        '%02d:%02d:%02d',
        $hours,
        $minutes,
        $remainingSeconds
    );
}


/* =========================================================
   ATTACHMENTS
========================================================= */

function get_assignment_files(
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
   SECURE FILE VIEW / DOWNLOAD
========================================================= */

if (
    !empty(
        $_GET['task_file']
    )
) {

    $fileId =
        positive_id(
            $_GET['task_file']
        );


    $fileMode =
        (
            $_GET['mode']
            ?? 'view'
        ) === 'download'

            ? 'download'
            : 'view';


    try {

        $file =
            one(
                "SELECT
                    f.*,
                    a.employee_id

                 FROM work_assignment_attachments f

                 JOIN work_assignments a
                    ON a.id=f.assignment_id

                 WHERE
                    f.id=?
                    AND a.employee_id=?
                    AND a.deleted_at IS NULL",

                [
                    $fileId,
                    $user['id']
                ]
            );

    } catch (Throwable $e) {

        fail(
            404,
            'Attachment not found.'
        );
    }


    if (!$file) {

        fail(
            404,
            'Attachment not found.'
        );
    }


    $relativePath =
        ltrim(
            (string) $file['file_path'],
            "/\\"
        );


    $storageBase =
        realpath(
            __DIR__
            .
            '/uploads/work-assignments'
        );


    $requestedFile =
        realpath(
            __DIR__
            .
            DIRECTORY_SEPARATOR
            .
            str_replace(
                [
                    '/',
                    '\\'
                ],
                DIRECTORY_SEPARATOR,
                $relativePath
            )
        );


    if (
        !$storageBase
        ||
        !$requestedFile
        ||
        !is_file($requestedFile)
        ||
        !is_readable($requestedFile)
    ) {

        fail(
            404,
            'File not found in storage.'
        );
    }


    $storagePrefix =
        rtrim(
            $storageBase,
            DIRECTORY_SEPARATOR
        )
        .
        DIRECTORY_SEPARATOR;


    if (
        strncmp(
            $requestedFile,
            $storagePrefix,
            strlen($storagePrefix)
        ) !== 0
    ) {

        fail(
            403,
            'Invalid attachment path.'
        );
    }


    $mimeType =
        trim(
            (string) (
                $file['mime_type']
                ?? ''
            )
        );


    if ($mimeType === '') {

        $finfo =
            new finfo(
                FILEINFO_MIME_TYPE
            );


        $mimeType =
            $finfo->file(
                $requestedFile
            )
            ?: 'application/octet-stream';
    }


    $originalName =
        trim(
            (string) (
                $file['original_name']
                ?? 'attachment'
            )
        );


    if ($originalName === '') {

        $originalName =
            'attachment';
    }


    $safeName =
        preg_replace(
            '/[^A-Za-z0-9._ -]/',
            '_',
            $originalName
        );


    if (
        !$safeName
        ||
        $safeName === ''
    ) {

        $safeName =
            'attachment';
    }


    while (
        ob_get_level() > 0
    ) {

        ob_end_clean();
    }


    $fileSize =
        filesize(
            $requestedFile
        );


    header(
        'X-Content-Type-Options: nosniff'
    );


    header(
        'Accept-Ranges: bytes'
    );


    header(
        'Cache-Control: private, max-age=3600'
    );


    header(
        'Content-Type: '
        .
        $mimeType
    );


    if (
        $fileMode ===
        'download'
    ) {

        header(
            'Content-Disposition: attachment; filename="'
            .
            $safeName
            .
            '"; filename*=UTF-8\'\''
            .
            rawurlencode(
                $originalName
            )
        );


        header(
            'Content-Length: '
            .
            $fileSize
        );


        readfile(
            $requestedFile
        );


        exit;
    }


    /*
     * INLINE VIEW.
     * Range support makes video playback / seeking work.
     */

    header(
        'Content-Disposition: inline; filename="'
        .
        $safeName
        .
        '"; filename*=UTF-8\'\''
        .
        rawurlencode(
            $originalName
        )
    );


    $start = 0;

    $end =
        $fileSize - 1;


    if (
        !empty(
            $_SERVER['HTTP_RANGE']
        )
        &&
        preg_match(
            '/bytes=(\d*)-(\d*)/',
            $_SERVER['HTTP_RANGE'],
            $matches
        )
    ) {

        if (
            $matches[1] !== ''
        ) {

            $start =
                (int) $matches[1];
        }


        if (
            $matches[2] !== ''
        ) {

            $end =
                (int) $matches[2];
        }


        $start =
            max(
                0,
                $start
            );


        $end =
            min(
                $fileSize - 1,
                $end
            );


        if (
            $start > $end
        ) {

            http_response_code(
                416
            );


            header(
                'Content-Range: bytes */'
                .
                $fileSize
            );


            exit;
        }


        http_response_code(
            206
        );


        header(
            'Content-Range: bytes '
            .
            $start
            .
            '-'
            .
            $end
            .
            '/'
            .
            $fileSize
        );
    }


    $length =
        $end
        -
        $start
        +
        1;


    header(
        'Content-Length: '
        .
        $length
    );


    $handle =
        fopen(
            $requestedFile,
            'rb'
        );


    if (!$handle) {

        fail(
            500,
            'Unable to open attachment.'
        );
    }


    fseek(
        $handle,
        $start
    );


    $remaining =
        $length;


    while (
        $remaining > 0
        &&
        !feof($handle)
    ) {

        $readLength =
            min(
                8192,
                $remaining
            );


        $buffer =
            fread(
                $handle,
                $readLength
            );


        if (
            $buffer === false
            ||
            $buffer === ''
        ) {

            break;
        }


        echo $buffer;


        $remaining -=
            strlen(
                $buffer
            );


        flush();
    }


    fclose(
        $handle
    );


    exit;
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
                        $_GET['assignment_date']
                ]
            );

    } catch (InvalidArgumentException $e) {

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
           START WORK
        ===================================================== */

        if (
            $action ===
            'start_assignment'
        ) {

            $assignmentId =
                positive_id(
                    $_POST['assignment_id']
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
                    'You can only start your own assigned task.'
                );
            }


            if (
                !empty(
                    $assignment['completed_at']
                )
            ) {

                throw new InvalidArgumentException(
                    'This task is already finished.'
                );
            }


            /*
             * First click only.
             * Refresh valla time reset avvadu.
             */

            if (
                empty(
                    $assignment['started_at']
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
                    $assignment['work_date']
                )
            );
        }


        /* =====================================================
           FINISH WORK
        ===================================================== */

        elseif (
            $action ===
            'finish_assignment'
        ) {

            $assignmentId =
                positive_id(
                    $_POST['assignment_id']
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
                    'You can only finish your own assigned task.'
                );
            }


            if (
                empty(
                    $assignment['started_at']
                )
            ) {

                throw new InvalidArgumentException(
                    'Please start the task before finishing it.'
                );
            }


            if (
                !empty(
                    $assignment['completed_at']
                )
            ) {

                throw new InvalidArgumentException(
                    'This task is already finished.'
                );
            }


            /* =============================================
               EXACT TIME
            ============================================= */

            $startedTimestamp =
                strtotime(
                    $assignment['started_at']
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
             * Existing admin reports work in minutes.
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


            /* =============================================
               WORK ASSIGNMENT
            ============================================= */

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


            /* =============================================
               DAILY WORK SHEET
            ============================================= */

            $sheet =
                work_sheet(
                    $user,
                    $assignment['work_date']
                );


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


            /* =============================================
               SAVE EMPLOYEE SUBMISSION
            ============================================= */

            $common = [

                'client_id' =>

                    $assignment['client_id']
                    === null

                        ? null

                        : (int)
                            $assignment['client_id'],


                'task_title' =>

                    $assignment['title'],


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
                $sheet['department_code'],
                $common,
                [],
                $entryId
            );


            sync_assignment(
                $assignmentId
            );


            submit_sheet(
                $sheet,
                false
            );


            /* =============================================
               ADMIN / MANAGER NOTIFICATION
            ============================================= */

            notify_staff(

                'work_submitted',

                $user['full_name']
                .
                ' finished an assigned task',

                $assignment['work_date']
                .
                ' · '
                .
                $assignment['title']
                .
                ' · Time '
                .
                assignment_exact_time(
                    $timeSpentSeconds
                )
                .
                (
                    $remark !== ''

                        ? ' · '
                            .
                            $remark

                        : ''
                ),

                (int) $sheet['id']

            );


            db()->commit();


            flash(
                'Task finished and submitted successfully.'
            );


            redirect(
                'employee-assigned-work.php?assignment_date='
                .
                urlencode(
                    $assignment['work_date']
                )
            );
        }


        /* =====================================================
           EXISTING DAILY / SOCIAL / CREATIVE SYSTEM
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


            /* =============================================
               EXISTING ASSIGNMENT UPDATE
            ============================================= */

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
                                $_POST['assignment_id']
                                ?? null
                            ),

                            $user['id']
                        ]
                    );


                if (!$assignment) {

                    fail(
                        403,
                        'You can only update your own assigned work.'
                    );
                }


                $assignmentId =
                    (int) $assignment['id'];


                $title =
                    $assignment['title'];


                $client =
                    $assignment['client_id'] === null

                        ? null

                        : (int)
                            $assignment['client_id'];
            }


            /* =============================================
               NORMAL ENTRY
            ============================================= */

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
                        $_POST['client_id']
                    )

                        ? null

                        : positive_id(
                            $_POST['client_id']
                        );


                if (
                    $client !== null
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


            /* =============================================
               SOCIAL MEDIA TASKS
            ============================================= */

            if (
                $action ===
                'save_social_tasks'
            ) {

                if (
                    $sheet['department_code']
                    !==
                    'social_media'
                ) {

                    throw new InvalidArgumentException(
                        'This task form is only for Social Media employees.'
                    );
                }


                $tasks =
                    $_POST['tasks']
                    ?? null;


                if (
                    !is_array($tasks)
                    ||
                    !$tasks
                ) {

                    throw new InvalidArgumentException(
                        'Add at least one client task before submitting.'
                    );
                }


                if (
                    filter_var(
                        $_POST['task_count']
                        ?? null,
                        FILTER_VALIDATE_INT
                    )
                    !==
                    count($tasks)
                ) {

                    throw new InvalidArgumentException(
                        'The task form was incomplete. Please try again.'
                    );
                }


                $validated = [];


                foreach (
                    array_values($tasks)
                    as $index => $task
                ) {

                    try {

                        if (
                            !is_array($task)
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
                        $task['item_count'];


                    unset(
                        $task['platforms'],
                        $task['item_count']
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

                            $task['work_type']
                            ===
                            'poster'

                                ? $count

                                : 0,


                        'reels_count' =>

                            $task['work_type']
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


            /* =============================================
               GRAPHIC / VIDEO TASKS
            ============================================= */

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
                    $_POST['tasks']
                    ?? null;


                if (
                    !is_array($tasks)
                    ||
                    !$tasks
                ) {

                    throw new InvalidArgumentException(
                        'Add at least one task before submitting.'
                    );
                }


                if (
                    filter_var(
                        $_POST['task_count']
                        ?? null,
                        FILTER_VALIDATE_INT
                    )
                    !==
                    count($tasks)
                ) {

                    throw new InvalidArgumentException(
                        'The task form was incomplete. Submit fewer tasks at a time and try again.'
                    );
                }


                $validated = [];


                foreach (
                    array_values($tasks)
                    as $index => $task
                ) {

                    try {

                        if (
                            !is_array($task)
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


                                'design_changes_count'
                                    => 0
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


            /* =============================================
               SUBMIT DAY
            ============================================= */

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
                            $sheet['id']
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


            /* =============================================
               EXISTING SINGLE ENTRY
            ============================================= */

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


                $timeSpent = null;


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
                        trim($duration)
                        !==
                        ''
                    ) {

                        $timeSpent =
                            parse_work_duration(
                                $duration
                            );
                    }
                }


                $entryId = null;


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
                                $sheet['id'],
                                $assignmentId
                            ]
                        );


                    $entryId =
                        $existing

                            ? (int)
                                $existing['id']

                            : null;
                }


                elseif (
                    !empty(
                        $_POST['entry_id']
                    )
                ) {

                    $entryId =
                        positive_id(
                            $_POST['entry_id']
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
                                $sheet['id']
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
                        $timeSpent === null
                    ) {

                        throw new InvalidArgumentException(
                            'Enter the time spent on this task.'
                        );
                    }
                }


                if (
                    $assignmentId !== null
                ) {

                    $common[
                        'assignment_id'
                    ] =
                        $assignmentId;
                }


                if (
                    $assignmentId !== null
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
                    $assignmentId !== null
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

                                ? ' · '
                                    .
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
                            $sheet['id']

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
                            $sheet['id']
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
   WORK SHEET
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
            $user['id'],
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
                $sheet['id']
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
   ASSIGNED WORK
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
                ) AS task_titles

             FROM work_assignments

             WHERE
                employee_id=?
                AND deleted_at IS NULL

             GROUP BY work_date

             ORDER BY work_date",

            [
                $user['id']
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
                    $user['id'],
                    $assignmentDate
                ]
            )

            : [];
}


else {

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
                $user['id'],
                $date,
                $date
            ]
        );
}


/* =========================================================
   ASSIGNMENT FILE MAP
========================================================= */

$assignmentFileMap = [];


foreach (
    $assignments
    as $assignment
) {

    $assignmentFileMap[
        $assignment['id']
    ] =
        get_assignment_files(
            (int) $assignment['id']
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
            $user['id']
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
            $user['id'],
            today()
        ]
    );


$editEntry = null;


if (
    !empty(
        $_GET['edit_entry']
    )
) {

    foreach (
        $entries
        as $entry
    ) {

        if (
            (int) $entry['id']
            ===
            (int) $_GET['edit_entry']
            &&
            !$entry['assignment_id']
        ) {

            $editEntry =
                $entry;
        }
    }


    if (!$editEntry) {

        fail(
            404,
            'Work entry not found.'
        );
    }
}


/* =========================================================
   PAGE START
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
        $user['full_name']
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

        <strong style="font-size:23px">

            <?= h(
                $user['full_name']
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
                    $user['id']
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

        <strong>+</strong>

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
            <?= h($date) ?>
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

        <strong style="font-size:23px">

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

            <p data-attendance-logout>

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


        <span class="attendance-card-link">

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

        <strong style="font-size:23px">

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

        <strong style="font-size:23px">

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
   DATE FILTER
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
            value="<?= h($date) ?>"
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
   ASSIGNED WORK PAGE
========================================================= */

if (
    $employeeView ===
    'assigned'
):

?>


<style>

/* =========================================================
   ASSIGNED TASK CARD
========================================================= */

.assigned-task-card {

    position: relative;

    margin-bottom: 26px;

    overflow: hidden;

}


.assigned-task-card::before {

    content: '';

    position: absolute;

    left: 0;
    top: 0;
    bottom: 0;

    width: 4px;

    background:
        currentColor;

    opacity: .15;

}


.assigned-task-section {

    margin-top: 16px;

    padding: 17px;

    border-radius: 12px;

    border: 1px solid
        rgba(0,0,0,.07);

    background:
        rgba(0,0,0,.018);

}


.assigned-task-section-title {

    display: block;

    margin-bottom: 9px;

    font-size: 12px;

    font-weight: 800;

    letter-spacing: .35px;

    text-transform: uppercase;

    opacity: .62;

}


.assigned-task-message {

    line-height: 1.75;

}


/* =========================================================
   REFERENCE LINK
========================================================= */

.assigned-reference-link {

    display: inline-flex;

    align-items: center;

    gap: 8px;

    padding: 9px 14px;

    border-radius: 8px;

    border: 1px solid
        rgba(0,0,0,.10);

    text-decoration: none;

    font-weight: 700;

}


/* =========================================================
   FILES
========================================================= */

.assigned-file-list {

    display: flex;

    flex-direction: column;

    gap: 10px;

}


.assigned-file-row {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 14px;

    padding: 12px 13px;

    border-radius: 9px;

    border: 1px solid
        rgba(0,0,0,.09);

}


.assigned-file-name {

    min-width: 0;

    overflow-wrap: anywhere;

    font-weight: 600;

}


.assigned-file-actions {

    display: flex;

    gap: 7px;

    flex-shrink: 0;

}


.assigned-file-button {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 78px;

    padding: 7px 11px;

    border-radius: 7px;

    border: 1px solid
        rgba(0,0,0,.12);

    text-decoration: none;

    font-size: 12px;

    font-weight: 700;

}


/* =========================================================
   IMAGE PREVIEW
========================================================= */

.assigned-image-grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fill,
            minmax(
                145px,
                1fr
            )
        );

    gap: 10px;

    margin-top: 14px;

}


.assigned-image-grid img {

    display: block;

    width: 100%;

    height: 155px;

    object-fit: cover;

    border-radius: 10px;

    border: 1px solid
        rgba(0,0,0,.08);

}


/* =========================================================
   VIDEO
========================================================= */

.assigned-video {

    display: block;

    width: 100%;

    max-width: 600px;

    margin-top: 14px;

    border-radius: 10px;

    background: #000;

}


/* =========================================================
   START / FINISH CONTROL
========================================================= */

.work-timer-panel {

    margin-top: 22px;

    padding: 23px;

    border-radius: 15px;

    border: 1px solid
        rgba(0,0,0,.08);

    background:
        rgba(0,0,0,.012);

}


/* =========================================================
   3 STEP PROGRESS
========================================================= */

.work-progress {

    display: grid;

    grid-template-columns:
        repeat(
            3,
            minmax(0,1fr)
        );

    gap: 8px;

    margin-bottom: 25px;

}


.work-progress-step {

    padding: 11px 8px;

    border-radius: 9px;

    background:
        rgba(0,0,0,.045);

    text-align: center;

    font-size: 11px;

    font-weight: 800;

    letter-spacing: .3px;

    opacity: .4;

}


.work-progress-step.active {

    background:
        rgba(37,99,235,.10);

    color: #2563eb;

    opacity: 1;

}


.work-progress-step.done {

    background:
        rgba(21,128,61,.10);

    color: #15803d;

    opacity: 1;

}


/* =========================================================
   STATUS BADGE
========================================================= */

.work-state {

    display: inline-flex;

    align-items: center;

    gap: 7px;

    padding: 7px 12px;

    margin-bottom: 17px;

    border-radius: 40px;

    font-size: 12px;

    font-weight: 800;

}


.work-state.ready {

    background:
        rgba(107,114,128,.12);

}


.work-state.running {

    background:
        rgba(37,99,235,.11);

    color: #2563eb;

}


.work-state.finished {

    background:
        rgba(21,128,61,.11);

    color: #15803d;

}


/* =========================================================
   TIMER
========================================================= */

.work-time-label {

    margin-bottom: 6px;

    font-size: 12px;

    font-weight: 700;

    opacity: .55;

}


.work-time {

    margin-bottom: 22px;

    font-size:
        clamp(
            34px,
            6vw,
            52px
        );

    line-height: 1;

    font-weight: 800;

    letter-spacing: 3px;

    font-variant-numeric:
        tabular-nums;

}


/* =========================================================
   START BUTTON
========================================================= */

.start-work-button {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 180px;

    min-height: 50px;

    font-size: 14px;

    font-weight: 800;

}


/* =========================================================
   FINISH BUTTON
========================================================= */

.finish-work-button {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-width: 190px;

    min-height: 50px;

    font-size: 14px;

    font-weight: 800;

}


.work-remark {

    margin: 19px 0;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width:700px) {

    .assigned-file-row {

        flex-direction:
            column;

        align-items:
            flex-start;
    }


    .assigned-file-actions {

        width: 100%;
    }


    .assigned-file-button {

        flex: 1;
    }


    .work-progress {

        gap: 4px;
    }


    .work-progress-step {

        font-size: 9px;

        padding:
            9px 4px;
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

                                View Work

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

            Assigned Work ·

            <?= h(
                $assignmentDate
            ) ?>

        </h2>


        <a
            class="button secondary"
            href="employee-assigned-work.php"
        >

            All Dates

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
            $assignmentFileMap[
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


        $startedTimestamp =
            $started

                ? strtotime(
                    $assignment[
                        'started_at'
                    ]
                )

                : 0;


    ?>


        <article
            class="work-card assigned-task-card"
        >


            <!-- =============================================
                 TASK TITLE
            ============================================== -->

            <div class="panel-heading">


                <h3>

                    Task
                    <?= $taskIndex + 1 ?>

                    ·

                    <?= h(
                        $assignment[
                            'title'
                        ]
                    ) ?>

                </h3>


            </div>


            <!-- =============================================
                 MESSAGE / TASK BRIEF
            ============================================== -->

            <div class="assigned-task-section">


                <span class="assigned-task-section-title">

                    Message / Task Brief

                </span>


                <div class="assigned-task-message">

                    <?= nl2br(
                        h(
                            $assignment[
                                'description'
                            ]
                        )
                    ) ?>

                </div>


            </div>


            <!-- =============================================
                 REFERENCE
            ============================================== -->

            <div class="assigned-task-section">


                <span class="assigned-task-section-title">

                    Reference / Drive Link

                </span>


                <?php if (
                    $referenceLink !== ''
                ): ?>


                    <a
                        class="assigned-reference-link"

                        href="<?= h(
                            $referenceLink
                        ) ?>"

                        target="_blank"

                        rel="noopener noreferrer"
                    >

                        ↗ Open Reference / Drive Link

                    </a>


                <?php else: ?>


                    <span class="muted">

                        No reference link added.

                    </span>


                <?php endif; ?>


            </div>


            <!-- =============================================
                 ATTACHED FILES
            ============================================== -->

            <div class="assigned-task-section">


                <span class="assigned-task-section-title">

                    Attached Files

                </span>


                <?php if (
                    $attachments
                ): ?>


                    <div class="assigned-file-list">


                        <?php


                        foreach (
                            $attachments
                            as $file
                        ):


                            $mime =
                                strtolower(
                                    (string) (
                                        $file[
                                            'mime_type'
                                        ]
                                        ?? ''
                                    )
                                );


                            $fileName =
                                $file[
                                    'original_name'
                                ];


                            $openUrl =
                                'employee-assigned-work.php'
                                .
                                '?task_file='
                                .
                                (int)
                                    $file[
                                        'id'
                                    ]
                                .
                                '&mode=view';


                            $downloadUrl =
                                'employee-assigned-work.php'
                                .
                                '?task_file='
                                .
                                (int)
                                    $file[
                                        'id'
                                    ]
                                .
                                '&mode=download';


                            if (
                                str_starts_with(
                                    $mime,
                                    'image/'
                                )
                            ) {

                                $icon =
                                    '🖼️';

                            } elseif (
                                str_starts_with(
                                    $mime,
                                    'video/'
                                )
                            ) {

                                $icon =
                                    '🎬';

                            } elseif (
                                $mime ===
                                'application/pdf'
                            ) {

                                $icon =
                                    '📄';

                            } else {

                                $icon =
                                    '📎';
                            }


                        ?>


                            <div class="assigned-file-row">


                                <div class="assigned-file-name">

                                    <?= $icon ?>

                                    <?= h(
                                        $fileName
                                    ) ?>

                                </div>


                                <div class="assigned-file-actions">


                                    <a
                                        class="assigned-file-button"

                                        href="<?= h(
                                            $openUrl
                                        ) ?>"

                                        target="_blank"

                                        rel="noopener"
                                    >

                                        Open

                                    </a>


                                    <a
                                        class="assigned-file-button"

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


                    <!-- =====================================
                         IMAGE PREVIEWS
                    ====================================== -->

                    <div class="assigned-image-grid">


                        <?php


                        foreach (
                            $attachments
                            as $file
                        ):


                            $mime =
                                strtolower(
                                    (string) (
                                        $file[
                                            'mime_type'
                                        ]
                                        ?? ''
                                    )
                                );


                            if (
                                !str_starts_with(
                                    $mime,
                                    'image/'
                                )
                            ) {

                                continue;
                            }


                            $imageUrl =
                                'employee-assigned-work.php'
                                .
                                '?task_file='
                                .
                                (int)
                                    $file[
                                        'id'
                                    ]
                                .
                                '&mode=view';


                        ?>


                            <a
                                href="<?= h(
                                    $imageUrl
                                ) ?>"

                                target="_blank"

                                rel="noopener"
                            >


                                <img
                                    src="<?= h(
                                        $imageUrl
                                    ) ?>"

                                    alt="<?= h(
                                        $file[
                                            'original_name'
                                        ]
                                    ) ?>"

                                    loading="lazy"
                                >


                            </a>


                        <?php endforeach; ?>


                    </div>


                    <!-- =====================================
                         VIDEO PLAYERS
                    ====================================== -->

                    <?php


                    foreach (
                        $attachments
                        as $file
                    ):


                        $mime =
                            strtolower(
                                (string) (
                                    $file[
                                        'mime_type'
                                    ]
                                    ?? ''
                                )
                            );


                        if (
                            !str_starts_with(
                                $mime,
                                'video/'
                            )
                        ) {

                            continue;
                        }


                        $videoUrl =
                            'employee-assigned-work.php'
                            .
                            '?task_file='
                            .
                            (int)
                                $file[
                                    'id'
                                ]
                            .
                            '&mode=view';


                    ?>


                        <video
                            class="assigned-video"

                            controls

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


            <!-- =============================================
                 WORK TIMER
            ============================================== -->

            <div class="work-timer-panel">


                <!-- 3 STEP STATUS -->

                <div class="work-progress">


                    <div
                        class="work-progress-step
                        <?=

                            !$started

                                ? 'active'

                                : 'done'

                        ?>"
                    >

                        1 · READY

                    </div>


                    <div
                        class="work-progress-step
                        <?=

                            $started
                            &&
                            !$finished

                                ? 'active'

                                : (
                                    $finished
                                        ? 'done'
                                        : ''
                                )

                        ?>"
                    >

                        2 · WORKING

                    </div>


                    <div
                        class="work-progress-step
                        <?=

                            $finished

                                ? 'done'

                                : ''

                        ?>"
                    >

                        3 · FINISHED

                    </div>


                </div>


                <!-- =====================================
                     READY
                ====================================== -->

                <?php if (
                    !$started
                ): ?>


                    <span class="work-state ready">

                        ○ READY TO START

                    </span>


                    <div class="work-time-label">

                        Work Time

                    </div>


                    <div class="work-time">

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


                        <button
                            class="primary start-work-button"
                            type="submit"
                        >

                            ▶ START WORK

                        </button>


                    </form>


                <?php endif; ?>


                <!-- =====================================
                     WORKING
                ====================================== -->

                <?php if (
                    $started
                    &&
                    !$finished
                ): ?>


                    <span class="work-state running">

                        ● WORK IN PROGRESS

                    </span>


                    <div class="work-time-label">

                        Live Work Time

                    </div>


                    <div
                        class="work-time"

                        data-assignment-live-timer

                        data-start="<?= (int)
                            $startedTimestamp
                        ?>"
                    >

                        00:00:00

                    </div>


                    <form method="post">


                        <?= csrf_field() ?>


                        <input
                            type="hidden"
                            name="action"
                            value="finish_assignment"
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

                        <div class="field full work-remark">


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

                                placeholder="Completed work details, final link, issue or remark"
                            ><?= h(
                                $assignment[
                                    'employee_remark'
                                ]
                                ?? ''
                            ) ?></textarea>


                        </div>


                        <button
                            class="primary finish-work-button"
                            type="submit"
                        >

                            ✓ FINISH WORK

                        </button>


                    </form>


                <?php endif; ?>


                <!-- =====================================
                     FINISHED
                ====================================== -->

                <?php if (
                    $finished
                ): ?>


                    <span class="work-state finished">

                        ✓ TASK FINISHED

                    </span>


                    <div class="work-time-label">

                        Total Time Taken

                    </div>


                    <div class="work-time">

                        <?= h(
                            assignment_exact_time(
                                $timeSpentSeconds
                            )
                        ) ?>

                    </div>


                    <p class="muted">

                        Started ·

                        <?= h(
                            date(
                                'd M Y, h:i:s A',

                                strtotime(
                                    $assignment[
                                        'started_at'
                                    ]
                                )
                            )
                        ) ?>

                    </p>


                    <p class="muted">

                        Finished ·

                        <?= h(
                            date(
                                'd M Y, h:i:s A',

                                strtotime(
                                    $assignment[
                                        'completed_at'
                                    ]
                                )
                            )
                        ) ?>

                    </p>


                    <div class="assigned-task-section">


                        <span class="assigned-task-section-title">

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


                                <span class="work-type-label">

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
   DAILY WORK FORM
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
   DETAIL DIALOGS
========================================================= */

foreach (
    $entries
    as $entry
) {

    detail_dialog(

        'entry-'
        .
        $entry['id'],

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
     LIVE TIMER
========================================================= -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const timers =
            document.querySelectorAll(
                '[data-assignment-live-timer]'
            );


        timers.forEach(
            function (timer) {


                const startedAt =
                    Number(
                        timer.dataset.start
                    );


                if (!startedAt) {

                    return;
                }


                function updateTimer() {


                    let totalSeconds =
                        Math.max(

                            0,

                            Math.floor(
                                Date.now()
                                /
                                1000
                            )
                            -
                            startedAt
                        );


                    const hours =
                        Math.floor(
                            totalSeconds
                            /
                            3600
                        );


                    totalSeconds =
                        totalSeconds
                        %
                        3600;


                    const minutes =
                        Math.floor(
                            totalSeconds
                            /
                            60
                        );


                    const seconds =
                        totalSeconds
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
                            seconds
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