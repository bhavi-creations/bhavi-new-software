<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';


$user = require_roles(['admin', 'manager']);

$error = null;

$assignmentView = $assignmentView ?? 'new';

$editId = (int) ($_GET['edit'] ?? 0);


/* =========================================================
   LIVE SERVER HELPERS
========================================================= */

function assignment_ini_bytes($value): int
{
    $value = trim((string) $value);

    if ($value === '') {
        return 0;
    }

    $last = strtolower(substr($value, -1));
    $number = (float) $value;

    if ($last === 'g') {
        $number *= 1024;
        $last = 'm';
    }

    if ($last === 'm') {
        $number *= 1024;
        $last = 'k';
    }

    if ($last === 'k') {
        $number *= 1024;
    }

    return (int) round($number);
}


function assignment_required_schema_check(): void
{
    $rows = rows("SHOW COLUMNS FROM work_assignments");

    $columns = [];

    foreach ($rows as $row) {
        if (!empty($row['Field'])) {
            $columns[(string) $row['Field']] = true;
        }
    }

    $required = [
        'id',
        'employee_id',
        'department_id',
        'client_id',
        'title',
        'description',
        'editing_type',
        'reference_link',
        'work_date',
        'status',
        'assigned_by',
        'deleted_at'
    ];

    $missing = [];

    foreach ($required as $column) {
        if (!isset($columns[$column])) {
            $missing[] = $column;
        }
    }

    if ($missing) {
        throw new RuntimeException(
            'Live database is missing required work_assignments columns: '
            .
            implode(', ', $missing)
        );
    }
}


/* Database schema is managed separately. Required columns/tables already exist. */

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

    $configuredMaxFileSize =
        100 * 1024 * 1024;

    $serverUploadLimit =
        assignment_ini_bytes(
            ini_get('upload_max_filesize')
        );

    $maxFileSize =
        $serverUploadLimit > 0
            ? min(
                $configuredMaxFileSize,
                $serverUploadLimit
            )
            : $configuredMaxFileSize;



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
                0775,
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



    if (!is_writable($absoluteDirectory)) {
        throw new RuntimeException(
            'Live upload folder is not writable: '
            .
            $absoluteDirectory
            .
            '. Give the PHP/web-server user write permission to this folder.'
        );
    }

    $fileInfo =
        class_exists('finfo')
            ? new finfo(FILEINFO_MIME_TYPE)
            : null;



    foreach (
        $files as $file
    ) {


        if (
            $file['error']
            !==
            UPLOAD_ERR_OK
        ) {

            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE =>
                    'File is larger than the live server upload_max_filesize setting.',
                UPLOAD_ERR_FORM_SIZE =>
                    'File is larger than the form upload limit.',
                UPLOAD_ERR_PARTIAL =>
                    'File upload was interrupted before completion.',
                UPLOAD_ERR_NO_FILE =>
                    'No file was received.',
                UPLOAD_ERR_NO_TMP_DIR =>
                    'Live server temporary upload folder is missing.',
                UPLOAD_ERR_CANT_WRITE =>
                    'Live server could not write the uploaded file to disk.',
                UPLOAD_ERR_EXTENSION =>
                    'A live server PHP extension stopped the file upload.'
            ];

            throw new RuntimeException(
                'Upload failed for "'
                .
                (string) ($file['name'] ?? 'attachment')
                .
                '": '
                .
                (
                    $uploadErrors[$file['error']]
                    ??
                    ('PHP upload error code ' . (int) $file['error'])
                )
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
                'Attachment "'
                .
                (string) ($file['name'] ?? 'file')
                .
                '" is too large for this live server. Maximum allowed per file is '
                .
                round($maxFileSize / 1024 / 1024, 1)
                .
                ' MB.'
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


        $mimeType = 'application/octet-stream';

        if ($fileInfo) {
            $detectedMime = $fileInfo->file($file['tmp_name']);
            if (is_string($detectedMime) && $detectedMime !== '') {
                $mimeType = $detectedMime;
            }
        } elseif (function_exists('mime_content_type')) {
            $detectedMime = mime_content_type($file['tmp_name']);
            if (is_string($detectedMime) && $detectedMime !== '') {
                $mimeType = $detectedMime;
            }
        }



        $allowed = false;



        /* IMAGE */
        if (
            in_array($extension, $allowedImageExtensions, true)
            &&
            (strpos($mimeType, 'image/') === 0 || $mimeType === 'application/octet-stream')
        ) {
            $allowed = true;
        }

        /* VIDEO */
        elseif (
            in_array($extension, $allowedVideoExtensions, true)
            &&
            (strpos($mimeType, 'video/') === 0 || $mimeType === 'application/octet-stream')
        ) {
            $allowed = true;
        }

        /* DOCUMENT */
        elseif (isset($documentMimeTypes[$mimeType])) {
            $allowed = true;
            $extension = $documentMimeTypes[$mimeType];
        }

        /* Some hosts report Office/ZIP files as generic binary MIME. */
        elseif (
            $mimeType === 'application/octet-stream'
            &&
            in_array($extension, ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip'], true)
        ) {
            $allowed = true;
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


    try {

        /*
         * On many live hosts, when post_max_size is exceeded,
         * PHP silently empties both $_POST and $_FILES.
         */
        $contentLength =
            isset($_SERVER['CONTENT_LENGTH'])
                ? (int) $_SERVER['CONTENT_LENGTH']
                : 0;

        $postLimit =
            assignment_ini_bytes(
                ini_get('post_max_size')
            );

        if (
            $contentLength > 0
            &&
            $postLimit > 0
            &&
            $contentLength > $postLimit
        ) {
            throw new RuntimeException(
                'Request is larger than the live server post_max_size limit. '
                .
                'Current server limit: '
                .
                round($postLimit / 1024 / 1024, 1)
                .
                ' MB. Reduce attachment size or increase post_max_size in hosting PHP settings.'
            );
        }

        check_csrf();

        assignment_required_schema_check();


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

        /* REMIND EMPLOYEE ABOUT A PENDING TASK */
        if (($_POST['action'] ?? '') === 'remind_assignment') {
            $remindTask = one('SELECT id,employee_id,title,work_date FROM work_assignments WHERE id=? AND deleted_at IS NULL AND status<>\'completed\'', [positive_id($_POST['id'] ?? null)]);
            if (!$remindTask) throw new InvalidArgumentException('This task is no longer pending.');
            query("INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)", [$remindTask['employee_id'], $user['id'], 'Reminder: task pending', date('d M Y', strtotime($remindTask['work_date'])) . ' · ' . $remindTask['title'] . ' — please complete and update your report.']);
            flash('Reminder sent to the employee.');
            $back = array_filter(['department' => (int) ($_POST['department'] ?? 0), 'employee' => (int) ($_POST['employee'] ?? 0)]);
            redirect('manager-assigned-status.php' . ($back ? '?' . http_build_query($back) : ''));
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

        // The redesigned form sends notify_present; old clients always notify.
        $notifyEmployees = !isset($_POST['notify_present']) || isset($_POST['notify_inapp']);



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

                'priority' =>
                    in_array((string) ($task['priority'] ?? 'normal'), ['normal', 'high', 'urgent'], true)
                        ? (string) $task['priority']
                        : 'normal',

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



            query('UPDATE work_assignments SET priority=? WHERE id=?', [$task['priority'], $editId]);

            /* Add newly uploaded files */

            save_assignment_attachments(
                $editId,
                $task['upload_index'],
                (int) $user['id']
            );



            /* Notification is sent after the assignment is committed. */

            db()->commit();

            if ($notifyEmployees) try {
                query(
                    "INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",
                    [
                        $task['employee_id'],
                        $user['id'],
                        'Work assignment updated',
                        $task['work_date'] . ' · ' . $task['title']
                    ]
                );
            } catch (Throwable $notificationError) {
                error_log('Assignment saved; notification failed: ' . $notificationError->getMessage());
            }

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
                        status,
                        assigned_by
                    )

                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?
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

                query('UPDATE work_assignments SET priority=? WHERE id=?', [$task['priority'], $assignmentId]);



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



            /* Notifications are sent after commit so notification errors never cancel saved assignments. */

            db()->commit();

            foreach ($notifyEmployees ? $employeeNotifications : [] as $employeeId => $notifications) {
                try {
                    $titles = [];
                    foreach ($notifications as $notification) {
                        $titles[] = $notification['title'];
                    }
                    $count = count($notifications);
                    query(
                        "INSERT INTO notifications (recipient_id,sender_id,notification_type,title,message) VALUES (?,?,'general',?,?)",
                        [
                            $employeeId,
                            $user['id'],
                            $count . ($count === 1 ? ' task assigned' : ' tasks assigned'),
                            implode(' · ', $titles)
                        ]
                    );
                } catch (Throwable $notificationError) {
                    error_log('Assignments saved; notification failed for employee ' . $employeeId . ': ' . $notificationError->getMessage());
                }
            }

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


        error_log(
            'manager-assign-work save error: ' .
            get_class($e) . ': ' .
            $e->getMessage() .
            ' in ' . $e->getFile() . ':' . $e->getLine()
        );

        /*
         * Admin/manager page: show the real error so live-only
         * hosting/database problems can be fixed instead of hiding
         * them behind a generic mutation_error() message.
         */
        if ($e instanceof PDOException) {
            $error =
                'Database save failed: '
                .
                $e->getMessage();
        } else {
            $message =
                trim(
                    (string) $e->getMessage()
                );

            $error =
                $message !== ''
                    ? $message
                    : (
                        'Unable to save assignment. '
                        .
                        get_class($e)
                    );
        }

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


<?php
$awEditingTypes = ['reel' => 'Reel', 'poster' => 'Poster', 'carousel' => 'Carousel', 'thumbnail' => 'Thumbnail', 'video_editing' => 'Video Editing', 'motion_graphics' => 'Motion Graphics', 'image_editing' => 'Image Editing', 'other' => 'Other'];
$awPriorities = ['normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent — today'];
$awTodayCounts = [];
foreach (rows("SELECT employee_id, COUNT(*) AS c FROM work_assignments WHERE work_date=CURDATE() AND deleted_at IS NULL AND status<>'completed' GROUP BY employee_id") as $awRow) $awTodayCounts[(int) $awRow['employee_id']] = (int) $awRow['c'];
$awInitials = static function (string $name): string { $out = ''; foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1)); return $out ?: '?'; };
$awBlank = ['employee_id' => '', 'client_id' => '', 'work_date' => today(), 'editing_type' => '', 'title' => '', 'description' => '', 'reference_link' => '', 'priority' => 'normal'];

if ($record) {
    $taskRows = [array_merge($awBlank, array_intersect_key($record, $awBlank), ['priority' => $record['priority'] ?? 'normal', 'editing_type' => $record['editing_type'] ?? '', 'reference_link' => $record['reference_link'] ?? ''])];
} elseif (is_array($_POST['tasks'] ?? null)) {
    $taskRows = $_POST['tasks'];
} else {
    $taskRows = [$awBlank];
}

$awRenderTask = static function ($index, array $row, bool $open) use ($employeeList, $clientList, $awEditingTypes, $awPriorities, $awTodayCounts, $awInitials, $record): void {
    $row = array_merge(['employee_id' => '', 'client_id' => '', 'work_date' => today(), 'editing_type' => '', 'title' => '', 'description' => '', 'reference_link' => '', 'priority' => 'normal'], $row);
    $name = 'tasks[' . $index . ']';
    $idp = 't' . $index;
    ?>
    <article class="aw-task<?= $open ? '' : ' is-collapsed' ?>" data-assignment-task data-index="<?= h((string) $index) ?>">
        <header class="aw-task-head">
            <span class="aw-task-num" data-task-num></span>
            <div class="aw-task-heading"><h3 data-task-heading>Task</h3><small class="aw-task-summary" data-task-summary></small></div>
            <?php if (!$record): ?>
                <button type="button" class="aw-icon-btn" data-copy-task title="Duplicate task" aria-label="Duplicate task">⧉</button>
                <button type="button" class="aw-icon-btn danger" data-remove-assignment-task title="Remove task" aria-label="Remove task"><?= icon('delete') ?></button>
                <button type="button" class="aw-edit-btn" data-edit-task>Edit</button>
            <?php endif; ?>
        </header>
        <div class="aw-task-body">
            <fieldset class="aw-employees"><legend>Assign to employee *</legend>
                <div class="aw-employee-grid">
                    <?php foreach ($employeeList as $employee): $eid = (int) $employee['id']; $count = $awTodayCounts[$eid] ?? 0; ?>
                        <label class="aw-employee" data-department="<?= (int) $employee['department_id'] ?>">
                            <input type="radio" data-task-employee name="<?= $name ?>[employee_id]" value="<?= $eid ?>" <?= (string) $row['employee_id'] === (string) $eid ? 'checked' : '' ?> required>
                            <span class="aw-employee-card"><span class="aw-avatar"><?= h($awInitials($employee['full_name'])) ?></span><span><strong><?= h($employee['full_name']) ?></strong><small><?= h($employee['designation'] ?: 'Team member') ?> · <?= $count ? $count . ' task' . ($count === 1 ? '' : 's') . ' today' : 'Free today' ?></small></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="aw-empty-employees" data-no-employees hidden>No active employees in this department.</p>
                <p class="aw-empty-employees" data-pick-department>Choose a department first to see its employees.</p>
            </fieldset>
            <div class="aw-grid-3">
                <label>Client<select data-task-client name="<?= $name ?>[client_id]"><option value="">No client / internal work</option><?= options($clientList, 'client_name', $row['client_id'] ?? '') ?></select></label>
                <label>Type of editing *<select data-task-editing-type name="<?= $name ?>[editing_type]" required><option value="">Select type</option><?php foreach ($awEditingTypes as $k => $l): ?><option value="<?= h($k) ?>" <?= (string) $row['editing_type'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Work date *<input type="date" data-task-date name="<?= $name ?>[work_date]" value="<?= h($row['work_date'] ?: today()) ?>" required></label>
            </div>
            <fieldset class="emp-choice emp-choice-inline aw-priority"><legend>Priority</legend>
                <?php foreach ($awPriorities as $k => $l): ?><label><input type="radio" data-task-priority name="<?= $name ?>[priority]" value="<?= $k ?>" <?= ($row['priority'] ?: 'normal') === $k ? 'checked' : '' ?>><span><?= h($l) ?></span></label><?php endforeach; ?>
            </fieldset>
            <label>Title *<input data-task-title name="<?= $name ?>[title]" value="<?= h($row['title']) ?>" maxlength="255" placeholder="e.g. Diwali offer poster — 3 sizes" required></label>
            <label>Message / task brief *<textarea data-task-description name="<?= $name ?>[description]" maxlength="5000" rows="4" placeholder="Explain complete task requirements — sizes, text, colours, deadline, where it will be posted…" required><?= h($row['description']) ?></textarea></label>
            <label>Reference link / Drive link<span class="aw-link-input"><?= icon('document') ?><input type="url" data-task-reference name="<?= $name ?>[reference_link]" value="<?= h($row['reference_link']) ?>" placeholder="https://drive.google.com/… or reference URL"></span><small class="aw-hint">Optional — Google Drive, Canva, YouTube, website or any reference URL</small></label>
            <label class="emp-drop aw-drop"><input type="file" data-task-attachments name="<?= $name ?>[attachments][]" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" multiple><span class="emp-drop-icon"><?= icon('download') ?></span><strong>Images / Videos / Documents — drop here or click to choose</strong><small>Multiple files · Images, videos, PDF, Word, Excel, PowerPoint, ZIP</small><span class="emp-drop-files" data-file-preview></span></label>
            <?php if ($record): $existingAttachments = assignment_attachments((int) $record['id']); if ($existingAttachments): ?>
                <div class="aw-existing"><small>Existing attachments</small><?php foreach ($existingAttachments as $attachment): ?><a href="<?= h($attachment['file_path']) ?>" target="_blank" rel="noopener"><?= h($attachment['original_name']) ?></a><?php endforeach; ?></div>
            <?php endif; endif; ?>
        </div>
    </article>
    <?php
};
$awDepartmentId = (string) $value('department_id');
?>
<form method="post" enctype="multipart/form-data" class="emp-page aw-page" id="assignmentForm" data-aw-form><?= csrf_field() ?><input type="hidden" name="notify_present" value="1">
<header class="emp-header">
    <div><p class="emp-crumb">Dashboard / Assign work / <?= $record ? 'Edit' : 'New' ?></p><h2 class="emp-title"><?= $record ? 'Edit assignment' : 'New assignment' ?></h2><p class="emp-sub">Pick a department, then add one or more tasks for your team.</p></div>
    <a class="emp-btn emp-btn-outline" href="manager-assigned-status.php">View assigned status</a>
</header>
<div class="emp-layout">
<div class="emp-main">
    <section class="aw-step">
        <div><small>Step 1</small><h3>Choose department</h3><p>Employee list below shows only this team</p></div>
        <label>Department *<select id="department_id" name="department_id" required data-aw-department><option value="">Select department</option><?= options($departmentList, 'name', $awDepartmentId) ?></select></label>
    </section>
    <div id="assignmentTasks" class="aw-tasks" data-assignment-tasks>
        <?php $last = count($taskRows) - 1; $i = 0; foreach ($taskRows as $index => $taskRow) { $awRenderTask(is_int($index) ? $index : $i, is_array($taskRow) ? $taskRow : [], $i === $last); $i++; } ?>
    </div>
    <?php if (!$record): ?>
        <button type="button" class="aw-add-task" id="addAssignmentTask">＋ Add another task</button>
        <template id="assignmentTaskTemplate"><?php $awRenderTask('__I__', $awBlank, true); ?></template>
    <?php endif; ?>
</div>
<aside class="emp-side">
    <section class="emp-idcard aw-summary" aria-label="Summary">
        <h3 class="aw-summary-title">Summary</h3>
        <dl>
            <div><dt>Department</dt><dd data-sum-department>—</dd></div>
            <div><dt>Tasks</dt><dd data-sum-tasks>1</dd></div>
            <div><dt>Employees</dt><dd data-sum-employees>0</dd></div>
            <div><dt>Work date</dt><dd data-sum-date>—</dd></div>
        </dl>
    </section>
    <section class="emp-card aw-notify">
        <p class="emp-label">Notify employees</p>
        <label class="aw-check"><input type="checkbox" name="notify_inapp" value="1" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_present'])) ? (isset($_POST['notify_inapp']) ? 'checked' : '') : 'checked' ?>> In-app notification</label>
    </section>
    <section class="emp-card emp-actions">
        <button class="emp-btn emp-btn-gold" type="submit" data-aw-submit><?= $record ? 'Save changes' : 'Assign work <span data-sum-button>(1 task)</span> →' ?></button>
        <?php if ($record): ?><a class="emp-btn emp-btn-outline" href="manager-assign-work.php">Cancel</a><?php endif; ?>
    </section>
</aside>
</div>
</form>


<?php endif; ?>



<?php
/* =========================================================
   STATUS PAGE
========================================================= */
if ($assignmentView === 'status'):

$stToday = today();
$stInitials = static function (string $name): string { $out = ''; foreach (array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1)); return $out ?: '?'; };
$stDesignations = [];
foreach (rows('SELECT user_id,designation FROM employee_profiles') as $stRow) $stDesignations[(int) $stRow['user_id']] = $stRow['designation'];

$assignedEmployees = [];
$completedTasks = 0; $overdueTasks = 0; $olderPending = 0;
$stCounts = ['all' => 0, 'pending' => 0, 'overdue' => 0, 'done' => 0];
$stGroups = [];
foreach ($assignments as $assignment) {
    $eid = (int) $assignment['employee_id'];
    $done = $assignment['reported_status'] === 'completed';
    $daysLate = $done ? 0 : max(0, (int) round((strtotime($stToday) - strtotime($assignment['work_date'])) / 86400));
    $state = $done ? 'done' : ($daysLate > 0 ? 'overdue' : 'pending');
    $assignedEmployees[$eid] ??= ['id' => $eid, 'name' => $assignment['full_name'], 'department' => $assignment['department_name'], 'total' => 0, 'completed' => 0, 'overdue' => 0];
    $assignedEmployees[$eid]['total']++;
    if ($done) { $assignedEmployees[$eid]['completed']++; $completedTasks++; }
    if ($state === 'overdue') { $assignedEmployees[$eid]['overdue']++; $overdueTasks++; }
    if (!$done && $daysLate > 2) $olderPending++;
    $stCounts['all']++; $stCounts[$state === 'done' ? 'done' : ($state === 'overdue' ? 'overdue' : 'pending')]++;
    $assignment['_state'] = $state; $assignment['_late'] = $daysLate;
    $stGroups[$assignment['work_date']][] = $assignment;
}
$stTotal = count($assignments);
$stPending = $stTotal - $completedTasks;
$stPercent = $stTotal ? (int) round(100 * $completedTasks / $stTotal) : 0;
$finishedEmployees = count(array_filter($assignedEmployees, static fn($e) => $e['total'] === $e['completed']));
$stQuery = array_filter(['department' => $filterDepartment, 'employee' => $filterEmployee]);
$stBase = 'manager-assigned-status.php';
?>
<div class="emp-page st-page" data-st-page>
<header class="emp-header">
    <div><p class="emp-crumb">Dashboard / Assigned status</p><h2 class="emp-title">Assigned work status</h2><p class="emp-sub">Track every task you've assigned — who's done, who's pending.</p></div>
    <div class="st-head-actions">
        <a class="emp-btn emp-btn-outline" href="download-assignments.php<?= $stQuery ? '?' . h(http_build_query($stQuery)) : '' ?>"><?= icon('download') ?> Download PDF</a>
        <a class="emp-btn emp-btn-dark" href="manager-assign-work.php">＋ New assignment</a>
    </div>
</header>

<section class="st-stats">
    <div class="st-progress">
        <div class="st-ring" style="--p:<?= $stPercent ?>%"><strong><?= $stPercent ?>%</strong></div>
        <div><small>Overall progress</small><strong><?= $completedTasks ?> of <?= $stTotal ?> tasks done</strong><span>Employees completed all work: <?= $finishedEmployees ?> of <?= count($assignedEmployees) ?></span></div>
    </div>
    <div class="st-stat"><span>Employees assigned</span><strong><?= count($assignedEmployees) ?></strong></div>
    <div class="st-stat"><span>Completed tasks</span><strong><?= $completedTasks ?></strong></div>
    <div class="st-stat st-stat-warn"><span>Pending tasks</span><strong><?= $stPending ?></strong><?php if ($olderPending): ?><small><?= $olderPending ?> older than 2 days</small><?php endif; ?></div>
</section>

<section class="emp-card st-people">
    <div class="st-card-head"><h3>Employee progress</h3><small><?= $filterEmployee ? '<a href="' . h($stBase . (($q = array_filter(['department' => $filterDepartment])) ? '?' . http_build_query($q) : '')) . '">Show all employees</a>' : 'Click a person to see only their tasks' ?></small></div>
    <?php if (!$assignedEmployees): ?><p class="muted">No tasks assigned for this filter yet.</p><?php endif; ?>
    <div class="st-people-grid">
        <?php foreach ($assignedEmployees as $e): $pct = $e['total'] ? (int) round(100 * $e['completed'] / $e['total']) : 0; $q = array_filter(['department' => $filterDepartment, 'employee' => $e['id']]); ?>
        <a class="st-person<?= $e['overdue'] ? ' is-late' : '' ?><?= $filterEmployee === $e['id'] ? ' is-active' : '' ?>" href="<?= h($stBase . '?' . http_build_query($q)) ?>">
            <span class="st-person-top"><span class="aw-avatar"><?= h($stInitials($e['name'])) ?></span><span><strong><?= h($e['name']) ?></strong><small><?= h(($stDesignations[$e['id']] ?? '') ?: $e['department']) ?></small></span></span>
            <span class="st-bar"><i style="width:<?= $pct ?>%"></i></span>
            <span class="st-person-nums"><?php if ($e['overdue']): ?><b class="late"><?= $e['overdue'] ?> overdue</b><?php else: ?><b class="done"><?= $e['completed'] ?> done</b><?php endif; ?><b class="pending"><?= $e['total'] - $e['completed'] ?> pending</b><b><?= $e['total'] ?> total</b></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="emp-card st-table-card">
    <div class="st-table-head">
        <h3><?= $filterEmployee ? 'Selected employee tasks' : 'Assigned work' ?></h3>
        <div class="dept-toggle st-tabs" role="tablist">
            <?php foreach (['all' => 'All', 'pending' => 'Pending', 'overdue' => 'Overdue', 'done' => 'Done'] as $k => $l): ?><button type="button" class="<?= $k === 'all' ? 'active' : '' ?>" data-st-tab="<?= $k ?>"><?= $l ?> · <?= $stCounts[$k] ?></button><?php endforeach; ?>
        </div>
        <form method="get" class="st-filters" data-st-filters>
            <label class="sr-only" for="department">Department</label><select id="department" name="department" data-filter-department><option value="">All departments</option><?= options(departments(), 'name', $filterDepartment) ?></select>
            <label class="sr-only" for="employee">Employee</label><select id="employee" name="employee"><option value="">All employees</option><?= options($assignedEmployeeList, 'full_name', $filterEmployee) ?></select>
            <noscript><button class="emp-btn emp-btn-outline" type="submit">Apply</button></noscript>
        </form>
    </div>
    <div class="table-scroll">
        <table class="st-table">
            <thead><tr><th>Task &amp; client</th><th>Assigned to</th><th>Type</th><th>Reference &amp; files</th><th>Status</th><th class="st-action-col">Action</th></tr></thead>
            <?php if (!$assignments): ?><tbody><?php empty_row(6, 'No assigned work for this filter.'); ?></tbody><?php endif; ?>
            <?php foreach ($stGroups as $date => $items):
                $daysAgo = (int) round((strtotime($stToday) - strtotime($date)) / 86400);
                $pendingHere = count(array_filter($items, static fn($a) => $a['_state'] !== 'done'));
                $groupLate = $daysAgo > 0 && $pendingHere > 0;
                $label = ($daysAgo === 0 ? 'Today · ' : ($daysAgo === -1 ? 'Tomorrow · ' : '')) . date('D, d M Y', strtotime($date)) . ($daysAgo > 0 ? ' · ' . $daysAgo . ' day' . ($daysAgo === 1 ? '' : 's') . ' ago' : '');
            ?>
            <tbody class="st-group" data-st-group>
                <tr class="st-group-row<?= $groupLate ? ' is-late' : '' ?>"><th colspan="6"><?= h($label) ?> <span>· <?= $groupLate ? $pendingHere . ' task' . ($pendingHere === 1 ? '' : 's') . ' still pending' : count($items) . ' task' . (count($items) === 1 ? '' : 's') ?></span></th></tr>
                <?php foreach ($items as $assignment): $attachments = assignment_attachments((int) $assignment['id']); $state = $assignment['_state']; ?>
                <tr data-st-row data-state="<?= $state ?>">
                    <td><strong class="st-task-title"><?= h($assignment['title']) ?></strong><small class="st-sub"><?= h($assignment['client_name'] ?: 'Internal work') ?> · Task <?= (int) $taskNumbers[$assignment['id']] ?><?php if (($assignment['priority'] ?? 'normal') !== 'normal'): ?> · <em class="st-priority st-priority-<?= h($assignment['priority']) ?>"><?= $assignment['priority'] === 'urgent' ? 'Urgent' : 'High' ?></em><?php endif; ?></small></td>
                    <td><span class="st-person-cell"><span class="aw-avatar"><?= h($stInitials($assignment['full_name'])) ?></span><?= h($assignment['full_name']) ?></span></td>
                    <td><span class="st-chip"><?= h(ucwords(str_replace('_', ' ', $assignment['editing_type'] ?? 'Other')) ?: 'Other') ?></span></td>
                    <td><span class="st-files"><?php if (!empty($assignment['reference_link'])): ?><a class="st-chip st-chip-link" href="<?= h($assignment['reference_link']) ?>" target="_blank" rel="noopener">Link ↗</a><?php endif; ?><?php if ($attachments): ?><button type="button" class="st-chip st-chip-files" data-dialog="assignment-<?= (int) $assignment['id'] ?>"><?= count($attachments) ?> file<?= count($attachments) === 1 ? '' : 's' ?></button><?php endif; ?><?php if (empty($assignment['reference_link']) && !$attachments): ?><small class="muted">No link · No files</small><?php endif; ?></span></td>
                    <td><span class="st-status st-status-<?= $state ?>"><?= $state === 'done' ? 'Done' : ($state === 'overdue' ? 'Overdue · ' . $assignment['_late'] . 'd' : 'Pending') ?></span></td>
                    <td class="st-action-col"><div class="st-actions">
                        <?php if ($state === 'overdue'): ?>
                            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="remind_assignment"><input type="hidden" name="id" value="<?= (int) $assignment['id'] ?>"><input type="hidden" name="department" value="<?= (int) $filterDepartment ?>"><input type="hidden" name="employee" value="<?= (int) $filterEmployee ?>"><button type="submit" class="st-btn st-btn-dark">Remind</button></form>
                        <?php endif; ?>
                        <button type="button" class="st-btn" data-dialog="assignment-<?= (int) $assignment['id'] ?>">Open</button>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a class="icon-button" href="manager-assign-work.php?edit=<?= (int) $assignment['id'] ?>" title="Edit assignment" aria-label="Edit assignment"><?= icon('edit') ?></a>
                            <?php delete_button((int) $assignment['id'], 'delete_assignment', 'Delete this assignment?'); ?>
                        <?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
        </table>
    </div>
    <p class="st-more" data-st-more-wrap hidden><span data-st-showing></span> · <button type="button" data-st-more>Load more</button></p>
</section>
</div>
<?php
foreach ($assignments as $assignment) {
    $attachments = assignment_attachments((int) $assignment['id']);
    $files = [];
    foreach ($attachments as $attachment) $files[] = $attachment['original_name'];
    detail_dialog('assignment-' . $assignment['id'], $assignment['title'], [
        'Employee' => $assignment['full_name'],
        'Department' => $assignment['department_name'],
        'Client' => $assignment['client_name'],
        'Date' => $assignment['work_date'],
        'Priority' => ucfirst($assignment['priority'] ?? 'normal'),
        'Type of Editing' => ucwords(str_replace('_', ' ', $assignment['editing_type'] ?? '')),
        'Task' => $assignment['title'],
        'Message / Brief' => $assignment['description'],
        'Reference Link' => $assignment['reference_link'] ?? '',
        'Attachments' => $files ? implode(', ', $files) : 'No files',
        'Status' => $assignment['reported_status'],
        'Time spent' => format_work_duration($assignment['reported_time']),
        'Employee remark / issue' => $assignment['reported_remark'],
        'Assigned by' => $assignment['assigned_by_name'],
    ]);
    if ($attachments) {
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var d=document.getElementById(' . json_encode('assignment-' . $assignment['id']) . ');if(!d)return;var p=document.createElement("div");p.className="st-dialog-files";';
        foreach ($attachments as $attachment) echo 'var a=document.createElement("a");a.href=' . json_encode($attachment['file_path']) . ';a.target="_blank";a.rel="noopener";a.textContent=' . json_encode($attachment['original_name']) . ';p.appendChild(a);';
        echo 'd.appendChild(p);});</script>';
    }
}
?>
<script>
(function () {
    var page = document.querySelector('[data-st-page]'); if (!page) return;
    var PAGE = 25, limit = PAGE, tab = 'all';
    var rows = Array.prototype.slice.call(page.querySelectorAll('[data-st-row]'));
    var moreWrap = page.querySelector('[data-st-more-wrap]'), showing = page.querySelector('[data-st-showing]');
    function apply() {
        var match = rows.filter(function (r) { return tab === 'all' || r.dataset.state === tab; });
        rows.forEach(function (r) { r.hidden = true; });
        match.slice(0, limit).forEach(function (r) { r.hidden = false; });
        page.querySelectorAll('[data-st-group]').forEach(function (g) { g.hidden = !g.querySelector('[data-st-row]:not([hidden])'); });
        moreWrap.hidden = match.length <= limit;
        showing.textContent = 'Showing ' + Math.min(limit, match.length) + ' of ' + match.length + ' tasks';
    }
    page.querySelectorAll('[data-st-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
            tab = b.dataset.stTab; limit = PAGE;
            page.querySelectorAll('[data-st-tab]').forEach(function (x) { x.classList.toggle('active', x === b); });
            apply();
        });
    });
    page.querySelector('[data-st-more]').addEventListener('click', function () { limit += PAGE; apply(); });
    var filters = page.querySelector('[data-st-filters]');
    filters.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', function () {
        if (s.name === 'department') filters.querySelector('[name=employee]').value = '';
        filters.submit();
    }); });
    apply();
})();
</script>
<?php
endif;
?>



<?php
/* =========================================================
   ASSIGNMENT FORM JS (new + edit)
========================================================= */
if ($assignmentView === 'new'):
?>
<script>
(function () {
    var form = document.querySelector('[data-aw-form]'); if (!form) return;
    var container = document.getElementById('assignmentTasks');
    var dept = form.querySelector('[data-aw-department]');
    var addBtn = document.getElementById('addAssignmentTask');
    var tpl = document.getElementById('assignmentTaskTemplate');
    var nextIndex = 0;
    container.querySelectorAll('[data-assignment-task]').forEach(function (t) { var n = parseInt(t.dataset.index, 10); if (!isNaN(n) && n >= nextIndex) nextIndex = n + 1; });

    function tasks() { return Array.prototype.slice.call(container.querySelectorAll('[data-assignment-task]')); }
    function fmtDate(v) { if (!v) return '—'; var d = new Date(v + 'T00:00:00'); return isNaN(d) ? v : d.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}); }
    function checkedEmployee(task) { return task.querySelector('[data-task-employee]:checked'); }

    function filterEmployees(task) {
        var d = dept.value, shown = 0;
        task.querySelectorAll('.aw-employee').forEach(function (card) {
            var ok = d !== '' && card.dataset.department === d;
            card.hidden = !ok;
            var input = card.querySelector('input');
            input.disabled = !ok;
            if (!ok && input.checked) input.checked = false;
            if (ok) shown++;
        });
        task.querySelector('[data-pick-department]').hidden = d !== '';
        task.querySelector('[data-no-employees]').hidden = d === '' || shown > 0;
    }

    function summaryText(task) {
        var emp = checkedEmployee(task), title = task.querySelector('[data-task-title]').value.trim();
        var pr = task.querySelector('[data-task-priority]:checked');
        var parts = [emp ? emp.closest('.aw-employee').querySelector('strong').textContent : 'No employee yet', fmtDate(task.querySelector('[data-task-date]').value), pr ? pr.nextElementSibling.textContent : 'Normal'];
        return {title: title, meta: parts.join(' · ')};
    }

    function refresh() {
        var list = tasks(), employees = {}, dates = {};
        list.forEach(function (task, i) {
            task.querySelector('[data-task-num]').textContent = i + 1;
            var s = summaryText(task);
            task.querySelector('[data-task-heading]').textContent = 'Task ' + (i + 1) + (task.classList.contains('is-collapsed') && s.title ? ' · ' + s.title : '');
            task.querySelector('[data-task-summary]').textContent = s.meta;
            var emp = checkedEmployee(task); if (emp) employees[emp.value] = 1;
            var d = task.querySelector('[data-task-date]').value; if (d) dates[d] = 1;
            var rm = task.querySelector('[data-remove-assignment-task]'); if (rm) rm.hidden = list.length === 1;
            var ed = task.querySelector('[data-edit-task]'); if (ed) ed.hidden = !task.classList.contains('is-collapsed');
        });
        var n = list.length, dk = Object.keys(dates);
        form.querySelector('[data-sum-department]').textContent = dept.value ? dept.options[dept.selectedIndex].text : '—';
        form.querySelector('[data-sum-tasks]').textContent = n;
        form.querySelector('[data-sum-employees]').textContent = Object.keys(employees).length;
        form.querySelector('[data-sum-date]').textContent = dk.length === 0 ? '—' : (dk.length === 1 ? fmtDate(dk[0]) : 'Multiple dates');
        var b = form.querySelector('[data-sum-button]'); if (b) b.textContent = '(' + n + ' task' + (n === 1 ? '' : 's') + ')';
    }

    function openTask(task) {
        tasks().forEach(function (t) { t.classList.toggle('is-collapsed', t !== task); });
        refresh();
    }

    function bindFiles(task) {
        var input = task.querySelector('[data-task-attachments]'), out = task.querySelector('[data-file-preview]'), drop = input.closest('.aw-drop');
        input.addEventListener('change', function () { out.textContent = Array.prototype.map.call(input.files, function (f) { return f.name; }).join(' · '); });
        ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.add('is-drag'); }); });
        ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function () { drop.classList.remove('is-drag'); }); });
    }

    function newTask(copyFrom) {
        var html = tpl.innerHTML.split('__I__').join(String(nextIndex++));
        container.insertAdjacentHTML('beforeend', html);
        var task = container.lastElementChild;
        if (copyFrom) {
            ['[data-task-client]', '[data-task-editing-type]', '[data-task-date]', '[data-task-title]', '[data-task-description]', '[data-task-reference]'].forEach(function (sel) { task.querySelector(sel).value = copyFrom.querySelector(sel).value; });
            var emp = checkedEmployee(copyFrom), pr = copyFrom.querySelector('[data-task-priority]:checked');
            if (emp) { var m = task.querySelector('[data-task-employee][value="' + emp.value + '"]'); if (m) m.checked = true; }
            if (pr) task.querySelector('[data-task-priority][value="' + pr.value + '"]').checked = true;
        } else {
            var prev = tasks()[tasks().length - 2];
            if (prev) task.querySelector('[data-task-date]').value = prev.querySelector('[data-task-date]').value;
        }
        filterEmployees(task);
        bindFiles(task);
        openTask(task);
        task.scrollIntoView({behavior: 'smooth', block: 'start'});
        return task;
    }

    tasks().forEach(function (t) { filterEmployees(t); bindFiles(t); });
    dept.addEventListener('change', function () { tasks().forEach(filterEmployees); refresh(); });
    if (addBtn) addBtn.addEventListener('click', function () { newTask(null); });

    container.addEventListener('click', function (e) {
        var task = e.target.closest('[data-assignment-task]'); if (!task) return;
        if (e.target.closest('[data-remove-assignment-task]')) {
            if (tasks().length > 1) { var wasOpen = !task.classList.contains('is-collapsed'); task.remove(); if (wasOpen) openTask(tasks()[tasks().length - 1]); else refresh(); }
            return;
        }
        if (e.target.closest('[data-copy-task]')) { newTask(task); return; }
        if (e.target.closest('[data-edit-task]') || (task.classList.contains('is-collapsed') && e.target.closest('.aw-task-head'))) openTask(task);
    });
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);

    // Before the browser validates, open any collapsed task that still has an empty/invalid field.
    form.querySelector('[data-aw-submit]').addEventListener('click', function () {
        var bad = tasks().filter(function (t) { return Array.prototype.some.call(t.querySelectorAll('input,select,textarea'), function (el) { return !el.disabled && !el.checkValidity(); }); });
        if (bad.length && bad[0].classList.contains('is-collapsed')) openTask(bad[0]);
    });
    refresh();
})();
</script>
<?php endif; ?>



<?php

page_end();

?>
