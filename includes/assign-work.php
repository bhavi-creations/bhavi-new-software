<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';


$user = require_roles(['admin', 'manager']);

$error = null;

$assignmentView = $assignmentView ?? 'new';

$editId = (int) ($_GET['edit'] ?? 0);



/* =========================================================
   AUTO CREATE REQUIRED DATABASE FIELDS
   Runs safely even if fields/table already exist
========================================================= */

try {

    $editingTypeColumn = one(
        "SHOW COLUMNS
         FROM work_assignments
         LIKE 'editing_type'"
    );

    if (!$editingTypeColumn) {

        query(
            "ALTER TABLE work_assignments
             ADD COLUMN editing_type VARCHAR(50) NULL
             AFTER description"
        );

    }


    $referenceLinkColumn = one(
        "SHOW COLUMNS
         FROM work_assignments
         LIKE 'reference_link'"
    );

    if (!$referenceLinkColumn) {

        query(
            "ALTER TABLE work_assignments
             ADD COLUMN reference_link VARCHAR(2048) NULL
             AFTER editing_type"
        );

    }


    query(
        "CREATE TABLE IF NOT EXISTS work_assignment_attachments
        (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,

            assignment_id INT NOT NULL,

            original_name VARCHAR(255) NOT NULL,

            stored_name VARCHAR(255) NOT NULL,

            file_path VARCHAR(1000) NOT NULL,

            mime_type VARCHAR(150) DEFAULT NULL,

            file_size BIGINT UNSIGNED DEFAULT 0,

            uploaded_by INT DEFAULT NULL,

            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            KEY idx_work_assignment_attachment_assignment
                (assignment_id)

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );


} catch (Throwable $schemaError) {

    /*
     * Do not break whole page if hosting
     * does not permit ALTER TABLE automatically.
     */

}



/* =========================================================
   EXISTING RECORD
========================================================= */

$record = $editId

    ? one(
        "SELECT *
         FROM work_assignments
         WHERE
            id=?
            AND deleted_at IS NULL",
        [$editId]
    )

    : null;


if (
    $editId &&
    !$record
) {

    fail(
        404,
        'Assignment not found.'
    );

}


if (
    $editId &&
    $user['role'] !== 'admin'
) {

    fail(
        403,
        'Only administrators can edit assignments.'
    );

}



/* =========================================================
   ATTACHMENT FUNCTIONS
========================================================= */

function assignment_task_uploaded_files(
    array $files,
    int $taskIndex
): array {

    if (
        !isset(
            $files['name'][$taskIndex]['attachments']
        )
    ) {

        return [];

    }


    $names =
        (array) (
            $files['name'][$taskIndex]['attachments']
            ?? []
        );


    $tmpNames =
        (array) (
            $files['tmp_name'][$taskIndex]['attachments']
            ?? []
        );


    $errors =
        (array) (
            $files['error'][$taskIndex]['attachments']
            ?? []
        );


    $sizes =
        (array) (
            $files['size'][$taskIndex]['attachments']
            ?? []
        );


    $result = [];


    foreach (
        $names as $fileIndex => $name
    ) {


        $error =
            (int) (
                $errors[$fileIndex]
                ?? UPLOAD_ERR_NO_FILE
            );


        if (
            $error ===
            UPLOAD_ERR_NO_FILE
        ) {

            continue;

        }


        $result[] = [

            'name' =>
                (string) $name,

            'tmp_name' =>
                (string) (
                    $tmpNames[$fileIndex]
                    ?? ''
                ),

            'error' =>
                $error,

            'size' =>
                (int) (
                    $sizes[$fileIndex]
                    ?? 0
                )

        ];

    }


    return $result;

}



/* =========================================================
   SAVE ATTACHMENTS
========================================================= */

function save_assignment_attachments(
    int $assignmentId,
    int $taskIndex,
    int $uploadedBy
): void {


    if (
        empty($_FILES['tasks']) ||
        !is_array($_FILES['tasks'])
    ) {

        return;

    }


    $files =
        assignment_task_uploaded_files(
            $_FILES['tasks'],
            $taskIndex
        );


    if (!$files) {

        return;

    }



    /*
     * 100 MB per file.
     * PHP upload_max_filesize/post_max_size
     * should also permit required sizes.
     */

    $maxFileSize =
        100 * 1024 * 1024;



    $documentMimeTypes = [

        'application/pdf' =>
            'pdf',

        'application/msword' =>
            'doc',

        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
            'docx',

        'application/vnd.ms-excel' =>
            'xls',

        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' =>
            'xlsx',

        'application/vnd.ms-powerpoint' =>
            'ppt',

        'application/vnd.openxmlformats-officedocument.presentationml.presentation' =>
            'pptx',

        'text/plain' =>
            'txt',

        'application/zip' =>
            'zip'

    ];



    $allowedImageExtensions = [

        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'avif',
        'heic'

    ];


    $allowedVideoExtensions = [

        'mp4',
        'mov',
        'webm',
        'avi',
        'mpeg',
        'mpg',
        '3gp'

    ];



    $folder =
        date('Y/m');


    $relativeDirectory =
        'uploads/work-assignments/'
        .
        $folder;


    $absoluteDirectory =
        __DIR__
        .
        '/'
        .
        $relativeDirectory;



    if (
        !is_dir(
            $absoluteDirectory
        )
    ) {

        if (
            !mkdir(
                $absoluteDirectory,
                0755,
                true
            )
            &&
            !is_dir(
                $absoluteDirectory
            )
        ) {

            throw new RuntimeException(
                'Unable to create task attachment folder.'
            );

        }

    }



    $fileInfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );



    foreach (
        $files as $file
    ) {


        if (
            $file['error']
            !==
            UPLOAD_ERR_OK
        ) {

            throw new InvalidArgumentException(
                'One of the files could not be uploaded.'
            );

        }



        if (
            $file['size'] <= 0
        ) {

            continue;

        }



        if (
            $file['size'] >
            $maxFileSize
        ) {

            throw new InvalidArgumentException(
                'Each attachment must be 100 MB or smaller.'
            );

        }



        $originalName =
            basename(
                $file['name']
            );


        $extension =
            strtolower(
                pathinfo(
                    $originalName,
                    PATHINFO_EXTENSION
                )
            );


        $mimeType =
            $fileInfo->file(
                $file['tmp_name']
            )
            ?: 'application/octet-stream';



        $allowed = false;



        /* IMAGE */

        if (
            str_starts_with(
                $mimeType,
                'image/'
            )
            &&
            in_array(
                $extension,
                $allowedImageExtensions,
                true
            )
        ) {

            $allowed = true;

        }



        /* VIDEO */

        elseif (
            str_starts_with(
                $mimeType,
                'video/'
            )
            &&
            in_array(
                $extension,
                $allowedVideoExtensions,
                true
            )
        ) {

            $allowed = true;

        }



        /* DOCUMENT */

        elseif (
            isset(
                $documentMimeTypes[
                    $mimeType
                ]
            )
        ) {

            $allowed = true;

            $extension =
                $documentMimeTypes[
                    $mimeType
                ];

        }



        if (!$allowed) {

            throw new InvalidArgumentException(
                'Unsupported file: '
                .
                $originalName
            );

        }



        $storedName =
            bin2hex(
                random_bytes(20)
            )
            .
            '.'
            .
            $extension;



        $relativePath =
            $relativeDirectory
            .
            '/'
            .
            $storedName;



        $absolutePath =
            __DIR__
            .
            '/'
            .
            $relativePath;



        if (
            !move_uploaded_file(
                $file['tmp_name'],
                $absolutePath
            )
        ) {

            throw new RuntimeException(
                'Unable to save attachment: '
                .
                $originalName
            );

        }



        query(
            "INSERT INTO work_assignment_attachments
            (
                assignment_id,
                original_name,
                stored_name,
                file_path,
                mime_type,
                file_size,
                uploaded_by
            )

            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?
            )",

            [
                $assignmentId,
                $originalName,
                $storedName,
                $relativePath,
                $mimeType,
                $file['size'],
                $uploadedBy
            ]
        );

    }

}



/* =========================================================
   GET ATTACHMENTS
========================================================= */

function assignment_attachments(
    int $assignmentId
): array {

    return rows(
        "SELECT *
         FROM work_assignment_attachments
         WHERE assignment_id=?
         ORDER BY id",
        [
            $assignmentId
        ]
    );

}



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


        /* =====================================================
           DELETE ASSIGNMENT
        ===================================================== */

        if (
            ($_POST['action'] ?? '')
            ===
            'delete_assignment'
        ) {


            if (
                $user['role']
                !==
                'admin'
            ) {

                fail(
                    403,
                    'Only administrators can delete assignments.'
                );

            }


            query(
                "UPDATE work_assignments
                 SET deleted_at=NOW()
                 WHERE id=?",
                [
                    positive_id(
                        $_POST['id']
                        ?? null
                    )
                ]
            );


            flash(
                'Assignment deleted. Existing work reports are retained.'
            );


            redirect(
                'manager-assigned-status.php'
            );

        }



        /* =====================================================
           DEPARTMENT COMMON FOR ALL TASKS
        ===================================================== */

        $department =
            positive_id(
                $_POST['department_id']
                ?? null
            );



        /* =====================================================
           TASKS
        ===================================================== */

        $postedTasks =
            $_POST['tasks']
            ?? null;


        if (
            !is_array(
                $postedTasks
            )
            ||
            !$postedTasks
        ) {

            throw new InvalidArgumentException(
                'Add at least one task.'
            );

        }



        $taskItems = [];



        foreach (
            $postedTasks
            as $index => $task
        ) {


            if (
                !is_array(
                    $task
                )
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Invalid task details.'
                );

            }



            /* =================================================
               EMPLOYEE
            ================================================= */

            $employee =
                positive_id(
                    $task['employee_id']
                    ?? null
                );



            if (
                !one(
                    "SELECT u.id

                     FROM users u

                     JOIN employee_profiles ep
                        ON ep.user_id=u.id

                     WHERE
                        u.id=?
                        AND ep.department_id=?
                        AND u.role='employee'
                        AND u.account_status='active'
                        AND u.deleted_at IS NULL",

                    [
                        $employee,
                        $department
                    ]
                )
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Choose an active employee from selected department.'
                );

            }



            /* =================================================
               CLIENT
            ================================================= */

            $client = null;


            if (
                isset(
                    $task['client_id']
                )
                &&
                $task['client_id'] !== ''
            ) {


                $client =
                    positive_id(
                        $task['client_id']
                    );


                if (
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
                        'Task '
                        .
                        ($index + 1)
                        .
                        ': Choose an active client.'
                    );

                }

            }



            /* =================================================
               WORK DATE
            ================================================= */

            $workDate =
                trim(
                    (string) (
                        $task['work_date']
                        ?? ''
                    )
                );


            if (
                $workDate === ''
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Select work date.'
                );

            }


            $dateObject =
                DateTimeImmutable::createFromFormat(
                    'Y-m-d',
                    $workDate
                );


            if (
                !$dateObject
                ||
                $dateObject->format(
                    'Y-m-d'
                )
                !==
                $workDate
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Invalid work date.'
                );

            }



            /* =================================================
               EDITING TYPE
            ================================================= */

            $editingType =
                trim(
                    (string) (
                        $task['editing_type']
                        ?? ''
                    )
                );


            $allowedEditingTypes = [

                'reel',
                'poster',
                'carousel',
                'thumbnail',
                'video_editing',
                'motion_graphics',
                'image_editing',
                'other'

            ];


            if (
                !in_array(
                    $editingType,
                    $allowedEditingTypes,
                    true
                )
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Select type of editing.'
                );

            }



            /* =================================================
               TITLE
            ================================================= */

            $title =
                trim(
                    (string) (
                        $task['title']
                        ?? ''
                    )
                );


            if (
                $title === ''
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Enter title.'
                );

            }


            if (
                mb_strlen(
                    $title
                )
                >
                255
            ) {

                throw new InvalidArgumentException(
                    'Task title must not exceed 255 characters.'
                );

            }



            /* =================================================
               MESSAGE / DESCRIPTION
            ================================================= */

            $description =
                trim(
                    (string) (
                        $task['description']
                        ?? ''
                    )
                );


            if (
                $description === ''
            ) {

                throw new InvalidArgumentException(
                    'Task '
                    .
                    ($index + 1)
                    .
                    ': Enter message / task brief.'
                );

            }


            if (
                mb_strlen(
                    $description
                )
                >
                5000
            ) {

                throw new InvalidArgumentException(
                    'Task brief must not exceed 5,000 characters.'
                );

            }



            /* =================================================
               REFERENCE / DRIVE LINK
            ================================================= */

            $referenceLink =
                trim(
                    (string) (
                        $task['reference_link']
                        ?? ''
                    )
                );


            if (
                $referenceLink !== ''
            ) {


                if (
                    !filter_var(
                        $referenceLink,
                        FILTER_VALIDATE_URL
                    )
                ) {

                    throw new InvalidArgumentException(
                        'Task '
                        .
                        ($index + 1)
                        .
                        ': Enter a valid reference / Drive link.'
                    );

                }


                $scheme =
                    strtolower(
                        (
                            parse_url(
                                $referenceLink,
                                PHP_URL_SCHEME
                            )
                            ?: ''
                        )
                    );


                if (
                    !in_array(
                        $scheme,
                        [
                            'http',
                            'https'
                        ],
                        true
                    )
                ) {

                    throw new InvalidArgumentException(
                        'Task '
                        .
                        ($index + 1)
                        .
                        ': Reference link must use http or https.'
                    );

                }

            }



            /* =================================================
               FINAL TASK
            ================================================= */

            $taskItems[] = [

                'employee_id' =>
                    $employee,

                'client_id' =>
                    $client,

                'work_date' =>
                    $workDate,

                'editing_type' =>
                    $editingType,

                'title' =>
                    $title,

                'description' =>
                    $description,

                'reference_link' =>
                    $referenceLink,

                'upload_index' =>
                    (int) $index

            ];

        }



        /* =====================================================
           TRANSACTION
        ===================================================== */

        db()->beginTransaction();



        /* =====================================================
           EDIT EXISTING
        ===================================================== */

        if ($record) {


            $task =
                $taskItems[0];


            query(
                "SELECT id
                 FROM work_assignments
                 WHERE id=?
                 FOR UPDATE",
                [
                    $editId
                ]
            );



            /*
             * If employee already submitted work,
             * employee/client/date/department cannot change.
             */

            if (
                count_value(
                    "SELECT COUNT(*)
                     FROM daily_work_entries
                     WHERE assignment_id=?",
                    [
                        $editId
                    ]
                )
                &&
                (
                    $task['employee_id']
                    !==
                    (int) $record['employee_id']

                    ||

                    $task['client_id']
                    !==
                    (
                        $record['client_id'] === null
                            ? null
                            : (int) $record['client_id']
                    )

                    ||

                    $task['work_date']
                    !==
                    $record['work_date']

                    ||

                    $department
                    !==
                    (int) $record['department_id']
                )
            ) {

                throw new InvalidArgumentException(
                    'This task already has employee work reports. Employee, client, department or work date cannot be changed.'
                );

            }



            query(
                "UPDATE work_assignments

                 SET
                    employee_id=?,
                    department_id=?,
                    client_id=?,
                    title=?,
                    description=?,
                    editing_type=?,
                    reference_link=?,
                    work_date=?

                 WHERE id=?",

                [
                    $task['employee_id'],
                    $department,
                    $task['client_id'],
                    $task['title'],
                    $task['description'],
                    $task['editing_type'],
                    $task['reference_link'],
                    $task['work_date'],
                    $editId
                ]
            );



            /* Add newly uploaded files */

            save_assignment_attachments(
                $editId,
                $task['upload_index'],
                (int) $user['id']
            );



            /* Notify employee */

            query(
                "INSERT INTO notifications
                (
                    recipient_id,
                    sender_id,
                    notification_type,
                    title,
                    message
                )

                VALUES
                (
                    ?,
                    ?,
                    'general',
                    ?,
                    ?
                )",

                [
                    $task['employee_id'],
                    $user['id'],
                    'Work assignment updated',

                    $task['work_date']
                    .
                    ' · '
                    .
                    $task['title']
                ]
            );



            db()->commit();


            flash(
                'Assignment updated.'
            );


            redirect(
                'manager-assign-work.php'
            );

        }



        /* =====================================================
           INSERT MULTIPLE TASKS
        ===================================================== */

        else {


            $employeeNotifications = [];


            foreach (
                $taskItems as $task
            ) {


                query(
                    "INSERT INTO work_assignments
                    (
                        employee_id,
                        department_id,
                        client_id,
                        title,
                        description,
                        editing_type,
                        reference_link,
                        work_date,
                        assigned_by
                    )

                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )",

                    [
                        $task['employee_id'],
                        $department,
                        $task['client_id'],
                        $task['title'],
                        $task['description'],
                        $task['editing_type'],
                        $task['reference_link'],
                        $task['work_date'],
                        $user['id']
                    ]
                );



                $assignmentId =
                    (int) db()->lastInsertId();



                /* =============================================
                   ATTACHMENTS
                ============================================= */

                save_assignment_attachments(
                    $assignmentId,
                    $task['upload_index'],
                    (int) $user['id']
                );



                /* =============================================
                   EMPLOYEE NOTIFICATION GROUP
                ============================================= */

                $employeeId =
                    (int) $task['employee_id'];


                if (
                    !isset(
                        $employeeNotifications[
                            $employeeId
                        ]
                    )
                ) {

                    $employeeNotifications[
                        $employeeId
                    ] = [];

                }


                $employeeNotifications[
                    $employeeId
                ][] = [

                    'title' =>
                        $task['title'],

                    'date' =>
                        $task['work_date']

                ];

            }



            /* =================================================
               NOTIFICATION PER EMPLOYEE
            ================================================= */

            foreach (
                $employeeNotifications
                as $employeeId => $notifications
            ) {


                $titles = [];


                foreach (
                    $notifications as $notification
                ) {

                    $titles[] =
                        $notification['title'];

                }


                $count =
                    count(
                        $notifications
                    );


                query(
                    "INSERT INTO notifications
                    (
                        recipient_id,
                        sender_id,
                        notification_type,
                        title,
                        message
                    )

                    VALUES
                    (
                        ?,
                        ?,
                        'general',
                        ?,
                        ?
                    )",

                    [
                        $employeeId,

                        $user['id'],

                        $count
                        .
                        (
                            $count === 1
                                ? ' task assigned'
                                : ' tasks assigned'
                        ),

                        implode(
                            ' · ',
                            $titles
                        )
                    ]
                );

            }



            db()->commit();


            flash(
                count($taskItems)
                .
                ' tasks assigned. They are visible in employee dashboards.'
            );


            redirect(
                'manager-assign-work.php'
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
   DATA
========================================================= */

$value =
    static fn(
        $key,
        $fallback = ''
    ) =>
        $_POST[$key]
        ??
        $record[$key]
        ??
        $fallback;



$employeeList =
    array_filter(
        employees(),

        static fn($employee) =>
            $employee['account_status']
            ===
            'active'
    );


$clientList =
    clients();


$departmentList =
    departments();



/* =========================================================
   FILTERS
========================================================= */

$filterDepartment =
    (int) (
        $_GET['department']
        ?? 0
    );


$filterEmployee =
    (int) (
        $_GET['employee']
        ?? 0
    );


$assignments =
    assignment_rows(
        assignment_filter(
            $user
        )
    );



$assignedEmployeeList =
    rows(
        "SELECT DISTINCT
            u.id,
            u.full_name

         FROM users u

         JOIN work_assignments a
            ON a.employee_id=u.id

         WHERE
            a.deleted_at IS NULL
            AND u.deleted_at IS NULL"

        .
        (
            $filterDepartment
                ? ' AND a.department_id=?'
                : ''
        )

        .

        " ORDER BY u.full_name",

        $filterDepartment
            ? [$filterDepartment]
            : []
    );



/* =========================================================
   TASK NUMBERS
========================================================= */

$taskNumbers = [];

$taskCounters = [];


foreach (
    $assignments
    as $assignment
) {


    $group =
        $assignment['employee_id']
        .
        ':'
        .
        $assignment['work_date'];


    $taskNumbers[
        $assignment['id']
    ] =
        ($taskCounters[$group] ?? 0)
        + 1;


    $taskCounters[$group] =
        $taskNumbers[
            $assignment['id']
        ];

}



/* =========================================================
   PAGE
========================================================= */

page_start(

    $assignmentView === 'status'

        ? 'Assigned work status'

        : 'New assignment',


    $assignmentView === 'status'

        ? 'manager-assigned-status.php'

        : 'manager-assign-work.php'
);


error_message(
    $error
);



/* =========================================================
   NEW ASSIGNMENT PAGE
========================================================= */

if (
    $assignmentView === 'new'
):

?>


<style>

.assignment-task {

    margin-top: 22px;

}


.assignment-task h3 {

    margin-bottom: 20px;

}


.assignment-file-box {

    padding: 16px;

    border: 1px dashed
        rgba(0, 0, 0, .20);

    border-radius: 8px;

}


.assignment-file-preview {

    margin-top: 12px;

    display: flex;

    flex-wrap: wrap;

    gap: 8px;

}


.assignment-file-preview span {

    padding: 6px 10px;

    background:
        rgba(0, 0, 0, .06);

    border-radius: 5px;

    font-size: 12px;

}


.assignment-existing-files {

    display: flex;

    flex-wrap: wrap;

    gap: 8px;

    margin-top: 10px;

}


.assignment-existing-files a {

    display: inline-flex;

    padding: 6px 10px;

    border-radius: 6px;

    background:
        rgba(0, 0, 0, .06);

    text-decoration: none;

}


</style>



<section class="panel">


    <h2>

        <?= $record
            ? 'Edit assignment'
            : 'New assignment'
        ?>

    </h2>



    <form
        method="post"
        enctype="multipart/form-data"
    >


        <?= csrf_field() ?>



        <!-- ==================================================
             DEPARTMENT COMMON
        =================================================== -->

        <div class="fields">


            <div class="field">


                <label for="department_id">

                    Department

                </label>


                <select
                    id="department_id"
                    name="department_id"
                    required
                >


                    <option value="">

                        Select department

                    </option>


                    <?= options(
                        $departmentList,
                        'name',
                        $value(
                            'department_id'
                        )
                    ) ?>


                </select>


            </div>


        </div>



        <!-- ==================================================
             TASKS
        =================================================== -->

        <div
            id="assignmentTasks"
            data-assignment-tasks
        >


            <?php


            /*
             * EDIT = one existing task
             */

            if ($record) {


                $taskRows = [

                    [

                        'employee_id' =>
                            $record[
                                'employee_id'
                            ],

                        'client_id' =>
                            $record[
                                'client_id'
                            ],

                        'work_date' =>
                            $record[
                                'work_date'
                            ],

                        'editing_type' =>
                            $record[
                                'editing_type'
                            ]
                            ?? '',

                        'title' =>
                            $record[
                                'title'
                            ],

                        'description' =>
                            $record[
                                'description'
                            ],

                        'reference_link' =>
                            $record[
                                'reference_link'
                            ]
                            ?? ''

                    ]

                ];

            }


            /*
             * POST error = preserve entered tasks
             */

            elseif (
                is_array(
                    $_POST['tasks']
                    ?? null
                )
            ) {

                $taskRows =
                    $_POST['tasks'];

            }


            /*
             * NEW TASK
             */

            else {


                $taskRows = [

                    [

                        'employee_id' => '',

                        'client_id' => '',

                        'work_date' =>
                            today(),

                        'editing_type' =>
                            '',

                        'title' => '',

                        'description' => '',

                        'reference_link' =>
                            ''

                    ]

                ];

            }



            foreach (
                $taskRows
                as $index => $taskRow
            ):


                $taskRow =
                    is_array(
                        $taskRow
                    )
                        ? $taskRow
                        : [];


            ?>


                <div
                    class="panel assignment-task"

                    data-assignment-task
                >


                    <h3 data-task-heading>

                        Task
                        <?= $index + 1 ?>

                    </h3>



                    <div class="fields">



                        <!-- ===================================
                             CLIENT
                        ==================================== -->

                        <div class="field">


                            <label>

                                Client

                            </label>


                            <select

                                data-task-client

                                name="tasks[<?= $index ?>][client_id]"
                            >


                                <option value="">

                                    No client / internal work

                                </option>


                                <?= options(

                                    $clientList,

                                    'client_name',

                                    $taskRow[
                                        'client_id'
                                    ]
                                    ?? ''

                                ) ?>


                            </select>


                        </div>



                        <!-- ===================================
                             EMPLOYEE
                        ==================================== -->

                        <div class="field">


                            <label>

                                Employee

                            </label>


                            <select

                                data-task-employee

                                name="tasks[<?= $index ?>][employee_id]"

                                required
                            >


                                <option value="">

                                    Select employee

                                </option>


                                <?php

                                foreach (
                                    $employeeList
                                    as $employee
                                ):

                                ?>


                                    <option

                                        value="<?= (int) $employee['id'] ?>"

                                        data-department="<?= (int) $employee['department_id'] ?>"

                                        <?=

                                        (string) (
                                            $taskRow[
                                                'employee_id'
                                            ]
                                            ?? ''
                                        )

                                        ===

                                        (string) $employee['id']

                                            ? 'selected'

                                            : ''

                                        ?>

                                    >

                                        <?= h(
                                            $employee[
                                                'full_name'
                                            ]
                                        ) ?>

                                    </option>


                                <?php endforeach; ?>


                            </select>


                        </div>



                        <!-- ===================================
                             WORK DATE
                        ==================================== -->

                        <div class="field">


                            <label>

                                Work Date

                            </label>


                            <input

                                type="date"

                                data-task-date

                                name="tasks[<?= $index ?>][work_date]"

                                value="<?= h(

                                    $taskRow[
                                        'work_date'
                                    ]

                                    ?? today()

                                ) ?>"

                                required
                            >


                        </div>



                        <!-- ===================================
                             TYPE OF EDITING
                        ==================================== -->

                        <div class="field">


                            <label>

                                Type of Editing

                            </label>


                            <select

                                data-task-editing-type

                                name="tasks[<?= $index ?>][editing_type]"

                                required
                            >


                                <option value="">

                                    Select type

                                </option>


                                <?php

                                $editingTypes = [

                                    'reel' =>
                                        'Reel',

                                    'poster' =>
                                        'Poster',

                                    'carousel' =>
                                        'Carousel',

                                    'thumbnail' =>
                                        'Thumbnail',

                                    'video_editing' =>
                                        'Video Editing',

                                    'motion_graphics' =>
                                        'Motion Graphics',

                                    'image_editing' =>
                                        'Image Editing',

                                    'other' =>
                                        'Other'

                                ];


                                foreach (
                                    $editingTypes
                                    as $typeValue => $typeLabel
                                ):

                                ?>


                                    <option

                                        value="<?= h(
                                            $typeValue
                                        ) ?>"

                                        <?=

                                        (
                                            $taskRow[
                                                'editing_type'
                                            ]
                                            ?? ''
                                        )

                                        ===

                                        $typeValue

                                            ? 'selected'

                                            : ''

                                        ?>

                                    >

                                        <?= h(
                                            $typeLabel
                                        ) ?>

                                    </option>


                                <?php endforeach; ?>


                            </select>


                        </div>



                        <!-- ===================================
                             TITLE
                        ==================================== -->

                        <div class="field full">


                            <label>

                                Title

                            </label>


                            <input

                                data-task-title

                                name="tasks[<?= $index ?>][title]"

                                value="<?= h(

                                    $taskRow[
                                        'title'
                                    ]

                                    ?? ''

                                ) ?>"

                                maxlength="255"

                                placeholder="Enter task title"

                                required
                            >


                        </div>



                        <!-- ===================================
                             MESSAGE / BRIEF
                        ==================================== -->

                        <div class="field full">


                            <label>

                                Message / Task Brief

                            </label>


                            <textarea

                                data-task-description

                                name="tasks[<?= $index ?>][description]"

                                maxlength="5000"

                                placeholder="Explain complete task requirements"

                                required

                            ><?= h(

                                $taskRow[
                                    'description'
                                ]

                                ?? ''

                            ) ?></textarea>


                        </div>



                        <!-- ===================================
                             REFERENCE / DRIVE LINK
                        ==================================== -->

                        <div class="field full">


                            <label>

                                Reference Link / Drive Link

                            </label>


                            <input

                                type="url"

                                data-task-reference

                                name="tasks[<?= $index ?>][reference_link]"

                                value="<?= h(

                                    $taskRow[
                                        'reference_link'
                                    ]

                                    ?? ''

                                ) ?>"

                                placeholder="https://drive.google.com/... or reference URL"
                            >


                            <p class="help">

                                Optional — Google Drive,
                                Canva, YouTube, website
                                or any reference URL.

                            </p>


                        </div>



                        <!-- ===================================
                             FILES
                        ==================================== -->

                        <div class="field full">


                            <label>

                                Images / Videos / Documents

                            </label>


                            <div class="assignment-file-box">


                                <input

                                    type="file"

                                    data-task-attachments

                                    name="tasks[<?= $index ?>][attachments][]"

                                    accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"

                                    multiple
                                >


                                <p class="help">

                                    Multiple files can be selected.
                                    Images, videos, PDF, Word,
                                    Excel, PowerPoint and ZIP supported.

                                </p>


                                <div
                                    class="assignment-file-preview"
                                    data-file-preview
                                ></div>


                                <?php

                                if (
                                    $record &&
                                    $index === 0
                                ):


                                    $existingAttachments =
                                        assignment_attachments(
                                            (int) $record['id']
                                        );

                                ?>


                                    <?php if (
                                        $existingAttachments
                                    ): ?>


                                        <p class="help">

                                            Existing attachments

                                        </p>


                                        <div class="assignment-existing-files">


                                            <?php

                                            foreach (
                                                $existingAttachments
                                                as $attachment
                                            ):

                                            ?>


                                                <a
                                                    href="<?= h(
                                                        $attachment[
                                                            'file_path'
                                                        ]
                                                    ) ?>"

                                                    target="_blank"

                                                    rel="noopener"
                                                >

                                                    <?= h(
                                                        $attachment[
                                                            'original_name'
                                                        ]
                                                    ) ?>

                                                </a>


                                            <?php endforeach; ?>


                                        </div>


                                    <?php endif; ?>


                                <?php endif; ?>


                            </div>


                        </div>



                    </div>



                    <?php if (
                        !$record &&
                        $index > 0
                    ): ?>


                        <button

                            type="button"

                            class="button secondary"

                            data-remove-assignment-task
                        >

                            Remove Task

                        </button>


                    <?php endif; ?>


                </div>


            <?php endforeach; ?>


        </div>



        <?php if (!$record): ?>


            <button

                type="button"

                class="button secondary"

                id="addAssignmentTask"
            >

                + Add Task

            </button>


        <?php endif; ?>



        <!-- ==================================================
             FINAL SUBMIT
        =================================================== -->

        <div class="form-actions">


            <button
                class="primary"
                type="submit"
            >

                <?= $record

                    ? 'Save Changes'

                    : 'Assign Work'

                ?>

            </button>


            <?php if ($record): ?>


                <a
                    class="button secondary"
                    href="manager-assign-work.php"
                >

                    Cancel

                </a>


            <?php endif; ?>


        </div>


    </form>


</section>


<?php endif; ?>



<?php
/* =========================================================
   STATUS PAGE
========================================================= */

if (
    $assignmentView ===
    'status'
):


$assignedEmployees = [];

$completedTasks = 0;


foreach (
    $assignments
    as $assignment
) {


    $employeeId =
        $assignment[
            'employee_id'
        ];


    if (
        !isset(
            $assignedEmployees[
                $employeeId
            ]
        )
    ) {


        $assignedEmployees[
            $employeeId
        ] = [

            'name' =>
                $assignment[
                    'full_name'
                ],

            'department' =>
                $assignment[
                    'department_name'
                ],

            'total' => 0,

            'completed' => 0

        ];

    }


    $assignedEmployees[
        $employeeId
    ]['total']++;



    if (
        $assignment[
            'reported_status'
        ]
        ===
        'completed'
    ) {


        $assignedEmployees[
            $employeeId
        ]['completed']++;


        $completedTasks++;

    }

}



$finishedEmployees =
    count(
        array_filter(

            $assignedEmployees,

            static fn($employee) =>

                $employee['total']
                ===
                $employee['completed']

        )
    );

?>


<form
    method="get"
    class="filters"
>


    <div class="field">


        <label for="department">

            Department

        </label>


        <select
            id="department"
            name="department"
            data-filter-department
        >


            <option value="">

                All departments

            </option>


            <?= options(

                departments(),

                'name',

                $filterDepartment

            ) ?>


        </select>


    </div>



    <div class="field">


        <label for="employee">

            Employee

        </label>


        <select
            id="employee"
            name="employee"
        >


            <option value="">

                All employees

            </option>


            <?= options(

                $assignedEmployeeList,

                'full_name',

                $filterEmployee

            ) ?>


        </select>


    </div>



    <button
        class="primary"
        type="submit"
    >

        Apply filters

    </button>


    <button

        class="button secondary"

        type="submit"

        formaction="download-assignments.php"
    >

        Download Excel

    </button>


</form>



<div class="stats-grid">


    <article class="stat-card">


        <h2>

            Employees assigned

        </h2>


        <strong>

            <?= count(
                $assignedEmployees
            ) ?>

        </strong>


    </article>



    <article class="stat-card">


        <h2>

            Employees completed all work

        </h2>


        <strong>

            <?= $finishedEmployees ?>

        </strong>


    </article>



    <article class="stat-card">


        <h2>

            Completed tasks

        </h2>


        <strong>

            <?= $completedTasks ?>

        </strong>


    </article>



    <article class="stat-card">


        <h2>

            Pending tasks

        </h2>


        <strong>

            <?= count(
                $assignments
            )
            -
            $completedTasks ?>

        </strong>


    </article>


</div>



<section class="panel">


    <h2>

        Employee progress

    </h2>


    <div class="table-scroll">


        <table>


            <thead>


                <tr>

                    <th>Employee</th>

                    <th>Department</th>

                    <th>Assigned</th>

                    <th>Completed</th>

                    <th>Pending</th>

                </tr>


            </thead>


            <tbody>


                <?php


                if (
                    !$assignedEmployees
                ) {

                    empty_row(5);

                }


                foreach (
                    $assignedEmployees
                    as $employee
                ):

                ?>


                    <tr>


                        <td>

                            <?= h(
                                $employee[
                                    'name'
                                ]
                            ) ?>

                        </td>


                        <td>

                            <?= h(
                                $employee[
                                    'department'
                                ]
                            ) ?>

                        </td>


                        <td>

                            <?= $employee[
                                'total'
                            ] ?>

                        </td>


                        <td>

                            <?= $employee[
                                'completed'
                            ] ?>

                        </td>


                        <td>

                            <?=

                            $employee[
                                'total'
                            ]

                            -

                            $employee[
                                'completed'
                            ]

                            ?>

                        </td>


                    </tr>


                <?php endforeach; ?>


            </tbody>


        </table>


    </div>


</section>



<?php


foreach (
    $assignments
    as &$assignment
) {


    $assignment[
        'reported_time'
    ] =
        format_work_duration(
            $assignment[
                'reported_time'
            ]
        );

}


unset(
    $assignment
);


?>



<section class="panel">


    <h2>

        <?= $filterEmployee

            ? 'Selected employee tasks'

            : 'Assigned work'

        ?>

    </h2>



    <div class="table-scroll">


        <table>


            <thead>


                <tr>

                    <th>Date</th>

                    <th>
                        Employee / Department
                    </th>

                    <th>Client</th>

                    <th>Type</th>

                    <th>Task</th>

                    <th>Reference</th>

                    <th>Files</th>

                    <th>Status</th>

                    <th>Time</th>

                    <th>Remark</th>

                    <th>Assigned by</th>

                    <th>Actions</th>

                </tr>


            </thead>


            <tbody>


                <?php


                if (
                    !$assignments
                ) {

                    empty_row(12);

                }


                foreach (
                    $assignments
                    as $assignment
                ):


                    $attachments =
                        assignment_attachments(
                            (int) $assignment['id']
                        );


                ?>


                    <tr>


                        <td>

                            <?= h(
                                $assignment[
                                    'work_date'
                                ]
                            ) ?>

                        </td>



                        <td>

                            <?= h(
                                $assignment[
                                    'full_name'
                                ]
                            ) ?>

                            <br>

                            <small class="muted">

                                <?= h(
                                    $assignment[
                                        'department_name'
                                    ]
                                ) ?>

                            </small>

                        </td>



                        <td>

                            <?= h(
                                $assignment[
                                    'client_name'
                                ]
                                ?: 'Internal'
                            ) ?>

                        </td>



                        <td>

                            <?= h(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $assignment[
                                            'editing_type'
                                        ]
                                        ?? ''
                                    )
                                )
                            ) ?>

                        </td>



                        <td class="wrap">

                            <strong>

                                Task

                                <?= $taskNumbers[
                                    $assignment[
                                        'id'
                                    ]
                                ] ?>

                                ·

                                <?= h(
                                    $assignment[
                                        'title'
                                    ]
                                ) ?>

                            </strong>

                            <br>

                            <small class="muted">

                                <?= h(
                                    $assignment[
                                        'description'
                                    ]
                                ) ?>

                            </small>

                        </td>



                        <td>


                            <?php if (
                                !empty(
                                    $assignment[
                                        'reference_link'
                                    ]
                                )
                            ): ?>


                                <a

                                    href="<?= h(
                                        $assignment[
                                            'reference_link'
                                        ]
                                    ) ?>"

                                    target="_blank"

                                    rel="noopener"
                                >

                                    Open Link

                                </a>


                            <?php else: ?>


                                <span class="muted">

                                    —

                                </span>


                            <?php endif; ?>


                        </td>



                        <td>


                            <?php if (
                                $attachments
                            ): ?>


                                <?php

                                foreach (
                                    $attachments
                                    as $attachment
                                ):

                                ?>


                                    <a

                                        href="<?= h(
                                            $attachment[
                                                'file_path'
                                            ]
                                        ) ?>"

                                        target="_blank"

                                        rel="noopener"

                                        style="
                                            display:block;
                                            margin-bottom:4px;
                                        "
                                    >

                                        <?= h(
                                            $attachment[
                                                'original_name'
                                            ]
                                        ) ?>

                                    </a>


                                <?php endforeach; ?>


                            <?php else: ?>


                                <span class="muted">

                                    No files

                                </span>


                            <?php endif; ?>


                        </td>



                        <td>

                            <?= status_badge(
                                $assignment[
                                    'reported_status'
                                ]
                            ) ?>

                        </td>



                        <td>

                            <?= h(
                                $assignment[
                                    'reported_time'
                                ]
                            ) ?>

                        </td>



                        <td class="wrap">

                            <?= h(
                                $assignment[
                                    'reported_remark'
                                ]
                            ) ?>

                        </td>



                        <td>

                            <?= h(
                                $assignment[
                                    'assigned_by_name'
                                ]
                            ) ?>

                        </td>



                        <td>


                            <div class="actions">


                                <?php

                                detail_button(
                                    'assignment-'
                                    .
                                    $assignment[
                                        'id'
                                    ]
                                );

                                ?>


                                <a

                                    class="icon-button"

                                    href="manager-assign-work.php?edit=<?= (int) $assignment['id'] ?>"

                                    title="Edit assignment"

                                    aria-label="Edit assignment"
                                >

                                    <?= icon(
                                        'edit'
                                    ) ?>

                                </a>


                                <?php

                                delete_button(

                                    (int) $assignment[
                                        'id'
                                    ],

                                    'delete_assignment',

                                    'Delete this assignment?'

                                );

                                ?>


                            </div>


                        </td>


                    </tr>


                <?php endforeach; ?>


            </tbody>


        </table>


    </div>


</section>



<?php


foreach (
    $assignments
    as $assignment
) {


    $attachments =
        assignment_attachments(
            (int) $assignment['id']
        );


    $attachmentNames = [];


    foreach (
        $attachments
        as $attachment
    ) {

        $attachmentNames[] =
            $attachment[
                'original_name'
            ];

    }



    detail_dialog(

        'assignment-'
        .
        $assignment[
            'id'
        ],


        $assignment[
            'title'
        ],


        [

            'Employee' =>
                $assignment[
                    'full_name'
                ],

            'Department' =>
                $assignment[
                    'department_name'
                ],

            'Client' =>
                $assignment[
                    'client_name'
                ],

            'Date' =>
                $assignment[
                    'work_date'
                ],

            'Type of Editing' =>
                ucwords(
                    str_replace(
                        '_',
                        ' ',
                        $assignment[
                            'editing_type'
                        ]
                        ?? ''
                    )
                ),

            'Task' =>
                $assignment[
                    'title'
                ],

            'Message / Brief' =>
                $assignment[
                    'description'
                ],

            'Reference Link' =>
                $assignment[
                    'reference_link'
                ]
                ?? '',

            'Attachments' =>
                $attachmentNames

                    ? implode(
                        ', ',
                        $attachmentNames
                    )

                    : 'No files',

            'Status' =>
                $assignment[
                    'reported_status'
                ],

            'Time spent' =>
                $assignment[
                    'reported_time'
                ],

            'Employee remark / issue' =>
                $assignment[
                    'reported_remark'
                ],

            'Assigned by' =>
                $assignment[
                    'assigned_by_name'
                ]

        ]

    );

}


endif;

?>



<?php
/* =========================================================
   MULTIPLE TASK JS
========================================================= */

if (
    $assignmentView === 'new'
    &&
    !$record
):

?>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const container =
            document.getElementById(
                'assignmentTasks'
            );


        const addButton =
            document.getElementById(
                'addAssignmentTask'
            );


        const departmentSelect =
            document.getElementById(
                'department_id'
            );


        if (
            !container ||
            !addButton
        ) {

            return;

        }



        /* =====================================================
           CLIENT OPTIONS
        ===================================================== */

        const clientOptions = `

            <option value="">
                No client / internal work
            </option>

            <?= addslashes(

                options(
                    $clientList,
                    'client_name',
                    ''
                )

            ) ?>

        `;



        /* =====================================================
           EMPLOYEE OPTIONS
        ===================================================== */

        const employeeOptions = `

            <option value="">
                Select employee
            </option>

            <?php

            foreach (
                $employeeList
                as $employee
            ):

            ?>

                <option
                    value="<?= (int) $employee['id'] ?>"
                    data-department="<?= (int) $employee['department_id'] ?>"
                >

                    <?= addslashes(
                        h(
                            $employee[
                                'full_name'
                            ]
                        )
                    ) ?>

                </option>

            <?php endforeach; ?>

        `;



        const today =
            '<?= h(today()) ?>';



        /* =====================================================
           FILTER EMPLOYEES
        ===================================================== */

        function filterEmployees() {


            if (
                !departmentSelect
            ) {

                return;

            }


            const departmentId =
                departmentSelect.value;


            container
                .querySelectorAll(
                    '[data-task-employee]'
                )
                .forEach(
                    function (select) {


                        Array
                            .from(
                                select.options
                            )
                            .forEach(
                                function (option) {


                                    if (
                                        option.value === ''
                                    ) {

                                        option.hidden =
                                            false;

                                        return;

                                    }


                                    option.hidden =
                                        departmentId !== ''
                                        &&
                                        option.dataset.department
                                        !==
                                        departmentId;

                                }
                            );


                        const selected =
                            select.options[
                                select.selectedIndex
                            ];


                        if (
                            selected &&
                            selected.hidden
                        ) {

                            select.value =
                                '';

                        }

                    }
                );

        }



        if (
            departmentSelect
        ) {


            departmentSelect.addEventListener(
                'change',
                filterEmployees
            );

        }



        /* =====================================================
           FILE PREVIEW
        ===================================================== */

        function bindFilePreview(
            input
        ) {


            if (
                !input ||
                input.dataset.previewBound
            ) {

                return;

            }


            input.dataset.previewBound =
                '1';


            input.addEventListener(
                'change',
                function () {


                    const preview =
                        input
                            .closest(
                                '.assignment-file-box'
                            )
                            ?.querySelector(
                                '[data-file-preview]'
                            );


                    if (!preview) {

                        return;

                    }


                    preview.innerHTML =
                        '';


                    Array
                        .from(
                            input.files
                            || []
                        )
                        .forEach(
                            function (file) {


                                const item =
                                    document.createElement(
                                        'span'
                                    );


                                item.textContent =
                                    file.name;


                                preview.appendChild(
                                    item
                                );

                            }
                        );

                }
            );

        }



        function bindAllFilePreviews() {


            container
                .querySelectorAll(
                    '[data-task-attachments]'
                )
                .forEach(
                    bindFilePreview
                );

        }



        /* =====================================================
           UPDATE INDEXES
        ===================================================== */

        function updateTaskNumbers() {


            const tasks =
                container.querySelectorAll(
                    '[data-assignment-task]'
                );


            tasks.forEach(
                function (
                    task,
                    index
                ) {


                    const heading =
                        task.querySelector(
                            '[data-task-heading]'
                        );


                    if (heading) {

                        heading.textContent =
                            'Task '
                            +
                            (index + 1);

                    }



                    const client =
                        task.querySelector(
                            '[data-task-client]'
                        );


                    if (client) {

                        client.name =
                            `tasks[${index}][client_id]`;

                    }



                    const employee =
                        task.querySelector(
                            '[data-task-employee]'
                        );


                    if (employee) {

                        employee.name =
                            `tasks[${index}][employee_id]`;

                    }



                    const workDate =
                        task.querySelector(
                            '[data-task-date]'
                        );


                    if (workDate) {

                        workDate.name =
                            `tasks[${index}][work_date]`;

                    }



                    const editingType =
                        task.querySelector(
                            '[data-task-editing-type]'
                        );


                    if (editingType) {

                        editingType.name =
                            `tasks[${index}][editing_type]`;

                    }



                    const title =
                        task.querySelector(
                            '[data-task-title]'
                        );


                    if (title) {

                        title.name =
                            `tasks[${index}][title]`;

                    }



                    const description =
                        task.querySelector(
                            '[data-task-description]'
                        );


                    if (description) {

                        description.name =
                            `tasks[${index}][description]`;

                    }



                    const reference =
                        task.querySelector(
                            '[data-task-reference]'
                        );


                    if (reference) {

                        reference.name =
                            `tasks[${index}][reference_link]`;

                    }



                    const attachments =
                        task.querySelector(
                            '[data-task-attachments]'
                        );


                    if (attachments) {

                        attachments.name =
                            `tasks[${index}][attachments][]`;

                    }


                }
            );


            filterEmployees();

            bindAllFilePreviews();

        }



        /* =====================================================
           ADD TASK
        ===================================================== */

        addButton.addEventListener(
            'click',
            function () {


                const index =
                    container.querySelectorAll(
                        '[data-assignment-task]'
                    ).length;



                const task =
                    document.createElement(
                        'div'
                    );


                task.className =
                    'panel assignment-task';


                task.setAttribute(
                    'data-assignment-task',
                    ''
                );



                task.innerHTML = `


                    <h3 data-task-heading>

                        Task ${index + 1}

                    </h3>



                    <div class="fields">



                        <div class="field">

                            <label>
                                Client
                            </label>

                            <select
                                data-task-client
                                name="tasks[${index}][client_id]"
                            >

                                ${clientOptions}

                            </select>

                        </div>



                        <div class="field">

                            <label>
                                Employee
                            </label>

                            <select
                                data-task-employee
                                name="tasks[${index}][employee_id]"
                                required
                            >

                                ${employeeOptions}

                            </select>

                        </div>



                        <div class="field">

                            <label>
                                Work Date
                            </label>

                            <input
                                type="date"

                                data-task-date

                                name="tasks[${index}][work_date]"

                                value="${today}"

                                required
                            >

                        </div>



                        <div class="field">

                            <label>
                                Type of Editing
                            </label>

                            <select
                                data-task-editing-type

                                name="tasks[${index}][editing_type]"

                                required
                            >

                                <option value="">
                                    Select type
                                </option>

                                <option value="reel">
                                    Reel
                                </option>

                                <option value="poster">
                                    Poster
                                </option>

                                <option value="carousel">
                                    Carousel
                                </option>

                                <option value="thumbnail">
                                    Thumbnail
                                </option>

                                <option value="video_editing">
                                    Video Editing
                                </option>

                                <option value="motion_graphics">
                                    Motion Graphics
                                </option>

                                <option value="image_editing">
                                    Image Editing
                                </option>

                                <option value="other">
                                    Other
                                </option>

                            </select>

                        </div>



                        <div class="field full">

                            <label>
                                Title
                            </label>

                            <input
                                data-task-title

                                name="tasks[${index}][title]"

                                maxlength="255"

                                placeholder="Enter task title"

                                required
                            >

                        </div>



                        <div class="field full">

                            <label>
                                Message / Task Brief
                            </label>

                            <textarea
                                data-task-description

                                name="tasks[${index}][description]"

                                maxlength="5000"

                                placeholder="Explain complete task requirements"

                                required
                            ></textarea>

                        </div>



                        <div class="field full">

                            <label>
                                Reference Link / Drive Link
                            </label>

                            <input
                                type="url"

                                data-task-reference

                                name="tasks[${index}][reference_link]"

                                placeholder="https://drive.google.com/... or reference URL"
                            >

                            <p class="help">

                                Optional — Google Drive,
                                Canva, YouTube, website
                                or any reference URL.

                            </p>

                        </div>



                        <div class="field full">

                            <label>
                                Images / Videos / Documents
                            </label>

                            <div class="assignment-file-box">

                                <input
                                    type="file"

                                    data-task-attachments

                                    name="tasks[${index}][attachments][]"

                                    accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"

                                    multiple
                                >

                                <p class="help">

                                    Select multiple images,
                                    videos or documents.

                                </p>

                                <div
                                    class="assignment-file-preview"
                                    data-file-preview
                                ></div>

                            </div>

                        </div>


                    </div>



                    <button
                        type="button"

                        class="button secondary"

                        data-remove-assignment-task
                    >

                        Remove Task

                    </button>

                `;



                container.appendChild(
                    task
                );


                updateTaskNumbers();


                task.scrollIntoView({

                    behavior:
                        'smooth',

                    block:
                        'center'

                });

            }
        );



        /* =====================================================
           REMOVE TASK
        ===================================================== */

        container.addEventListener(
            'click',
            function (event) {


                const button =
                    event.target.closest(
                        '[data-remove-assignment-task]'
                    );


                if (!button) {

                    return;

                }


                const task =
                    button.closest(
                        '[data-assignment-task]'
                    );


                if (task) {

                    task.remove();

                }


                updateTaskNumbers();

            }
        );



        /* INITIAL */

        updateTaskNumbers();

    }
);

</script>


<?php endif; ?>



<?php

page_end();

?>