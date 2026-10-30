<?php

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/work.php';


/* =========================================================
   ADMIN ONLY
========================================================= */

$user = require_roles(['admin']);



/* =========================================================
   FILTER VALUES
========================================================= */

$filterDepartment = (int) ($_GET['department'] ?? 0);

$filterEmployee = (int) ($_GET['employee'] ?? 0);

$filterStatus = trim(
    (string) ($_GET['status'] ?? '')
);

$filterSubmitted = trim(
    (string) ($_GET['submitted'] ?? '')
);

$dateFrom = trim(
    (string) ($_GET['date_from'] ?? '')
);

$dateTo = trim(
    (string) ($_GET['date_to'] ?? '')
);

$search = trim(
    (string) ($_GET['q'] ?? '')
);



/* =========================================================
   EMPLOYEE LIST
========================================================= */

$employeeList = rows(
    "SELECT
        u.id,
        u.full_name,
        ep.department_id

     FROM users u

     JOIN employee_profiles ep
        ON ep.user_id=u.id

     WHERE
        u.role='employee'
        AND u.account_status='active'
        AND u.deleted_at IS NULL

     ORDER BY u.full_name"
);



/* =========================================================
   BUILD FILTER QUERY
========================================================= */

$where = [
    'a.deleted_at IS NULL'
];

$params = [];



/* Department */

if ($filterDepartment > 0) {

    $where[] =
        'a.department_id=?';

    $params[] =
        $filterDepartment;

}



/* Employee */

if ($filterEmployee > 0) {

    $where[] =
        'a.employee_id=?';

    $params[] =
        $filterEmployee;

}



/* Date from */

if ($dateFrom !== '') {

    $where[] =
        'a.work_date>=?';

    $params[] =
        $dateFrom;

}



/* Date to */

if ($dateTo !== '') {

    $where[] =
        'a.work_date<=?';

    $params[] =
        $dateTo;

}



/* Effective status */

if (
    in_array(
        $filterStatus,
        [
            'pending',
            'completed'
        ],
        true
    )
) {

    $where[] =
        "COALESCE(
            e.task_status,
            a.status,
            'pending'
        )=?";

    $params[] =
        $filterStatus;

}



/* Submitted */

if ($filterSubmitted === 'yes') {

    $where[] =
        'e.id IS NOT NULL';

} elseif (
    $filterSubmitted === 'no'
) {

    $where[] =
        'e.id IS NULL';

}



/* Search */

if ($search !== '') {

    $where[] = "
        (
            a.title LIKE ?
            OR a.description LIKE ?
            OR employee.full_name LIKE ?
            OR client.client_name LIKE ?
        )
    ";

    $searchLike =
        '%' . $search . '%';

    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;

}



$whereSql =
    implode(
        ' AND ',
        $where
    );



/* =========================================================
   LOAD ALL ADMIN ASSIGNED WORK
========================================================= */

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
|
| work_assignments = Admin assigned tasks
|
| daily_work_entries = Employee submitted updates
|
| Latest employee entry for each assignment is selected.
|
*/

$assignments = rows(
    "
    SELECT

        a.id AS assignment_id,

        a.employee_id,

        a.department_id,

        a.client_id,

        a.title,

        a.description,

        a.work_date,

        a.status AS assignment_status,

        a.time_spent_minutes AS assignment_time,

        a.employee_remark AS assignment_remark,


        employee.full_name AS employee_name,

        department.name AS department_name,

        client.client_name,

        assigner.full_name AS assigned_by_name,


        e.id AS entry_id,

        e.task_status AS employee_status,

        e.remark AS employee_remark,

        e.time_spent_minutes AS employee_time,

        e.task_title AS submitted_task_title,


        s.id AS submission_id,

        s.work_date AS submitted_work_date,

        s.submission_status,

        s.submitted_at,

        s.review_status


    FROM work_assignments a


    JOIN users employee
        ON employee.id=a.employee_id


    JOIN departments department
        ON department.id=a.department_id


    LEFT JOIN clients client
        ON client.id=a.client_id


    JOIN users assigner
        ON assigner.id=a.assigned_by


    /* ===============================================
       GET LATEST EMPLOYEE ENTRY FOR THIS ASSIGNMENT
    ================================================ */

    LEFT JOIN daily_work_entries e

        ON e.id = (

            SELECT e2.id

            FROM daily_work_entries e2

            WHERE
                e2.assignment_id=a.id

            ORDER BY e2.id DESC

            LIMIT 1

        )


    LEFT JOIN daily_work_submissions s
        ON s.id=e.submission_id


    WHERE
        $whereSql


    ORDER BY

        a.work_date DESC,

        a.employee_id ASC,

        a.id ASC
    ",
    $params
);



/* =========================================================
   TASK NUMBERS
========================================================= */

$taskNumbers = [];

$taskCounters = [];


foreach (
    $assignments as $assignment
) {

    $group =
        $assignment['employee_id']
        .
        ':'
        .
        $assignment['work_date'];


    $taskCounters[$group] =
        ($taskCounters[$group] ?? 0)
        + 1;


    $taskNumbers[
        $assignment['assignment_id']
    ] =
        $taskCounters[$group];

}



/* =========================================================
   STATISTICS
========================================================= */

$totalTasks =
    count($assignments);


$submittedTasks = 0;

$completedTasks = 0;

$pendingTasks = 0;


foreach (
    $assignments as $assignment
) {


    if (
        !empty(
            $assignment['entry_id']
        )
    ) {

        $submittedTasks++;

    }


    $status =
        $assignment['employee_status']
        ?:
        $assignment['assignment_status']
        ?:
        'pending';


    if ($status === 'completed') {

        $completedTasks++;

    } else {

        $pendingTasks++;

    }

}



/* =========================================================
   PAGE START
========================================================= */

page_start(
    'All Assigned Work',
    'admin-all-work.php'
);

?>


<style>

/* =========================================================
   ADMIN ALL WORK PAGE
========================================================= */

.admin-all-work-heading {

    display: flex;

    justify-content: space-between;
    align-items: center;

    gap: 20px;

    margin-bottom: 20px;

}


.admin-all-work-heading h2 {

    margin: 0;

}


.admin-all-work-links {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

}


/* =========================
   SUBMISSION INDICATOR
========================= */

.admin-work-submitted {

    display: inline-flex;

    align-items: center;

    padding: 5px 10px;

    border-radius: 30px;

    background:
        rgba(21, 128, 61, 0.10);

    color: #15803d;

    font-size: 12px;

    font-weight: 600;

}


.admin-work-not-submitted {

    display: inline-flex;

    align-items: center;

    padding: 5px 10px;

    border-radius: 30px;

    background:
        rgba(217, 119, 6, 0.10);

    color: #b45309;

    font-size: 12px;

    font-weight: 600;

}



/* =========================
   TASK TITLE
========================= */

.admin-work-task-title {

    font-weight: 600;

}


.admin-work-task-brief {

    margin-top: 5px;

    font-size: 12px;

    color: #777;

}



/* =========================
   MOBILE
========================= */

@media (max-width: 767px) {

    .admin-all-work-heading {

        flex-direction: column;

        align-items: flex-start;

    }

}

</style>



<!-- =========================================================
     PAGE HEADING
========================================================= -->

<div class="admin-all-work-heading">


    <div>

        <h2>
            All Assigned Work
        </h2>

        <p class="muted">

            View work assigned by admin and updates submitted by employees.

        </p>

    </div>


    <div class="admin-all-work-links">


        <a
            href="manager-assign-work.php"
            class="button secondary"
        >

            + Assign Work

        </a>


        <a
            href="manager-assigned-status.php"
            class="button secondary"
        >

            Assigned Status

        </a>


    </div>


</div>



<!-- =========================================================
     FILTERS
========================================================= -->

<form
    method="get"
    class="filters"
>


    <!-- DEPARTMENT -->

    <div class="field">

        <label for="department">

            Department

        </label>


        <select
            id="department"
            name="department"
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



    <!-- EMPLOYEE -->

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


            <?php foreach (
                $employeeList
                as $employee
            ): ?>


                <option

                    value="<?= (int) $employee['id'] ?>"

                    <?= (int) $employee['id']
                        ===
                        $filterEmployee
                            ? 'selected'
                            : ''
                    ?>

                >

                    <?= h(
                        $employee['full_name']
                    ) ?>

                </option>


            <?php endforeach; ?>


        </select>

    </div>



    <!-- STATUS -->

    <div class="field">

        <label for="status">

            Task status

        </label>


        <select
            id="status"
            name="status"
        >

            <option value="">

                All statuses

            </option>


            <option
                value="pending"
                <?= $filterStatus === 'pending'
                    ? 'selected'
                    : ''
                ?>
            >

                Pending

            </option>


            <option
                value="completed"
                <?= $filterStatus === 'completed'
                    ? 'selected'
                    : ''
                ?>
            >

                Completed

            </option>


        </select>

    </div>



    <!-- SUBMITTED -->

    <div class="field">

        <label for="submitted">

            Employee submission

        </label>


        <select
            id="submitted"
            name="submitted"
        >

            <option value="">

                All

            </option>


            <option
                value="yes"
                <?= $filterSubmitted === 'yes'
                    ? 'selected'
                    : ''
                ?>
            >

                Submitted

            </option>


            <option
                value="no"
                <?= $filterSubmitted === 'no'
                    ? 'selected'
                    : ''
                ?>
            >

                Not submitted

            </option>


        </select>

    </div>



    <!-- DATE FROM -->

    <div class="field">

        <label for="date_from">

            Date from

        </label>


        <input
            id="date_from"
            name="date_from"
            type="date"
            value="<?= h($dateFrom) ?>"
        >

    </div>



    <!-- DATE TO -->

    <div class="field">

        <label for="date_to">

            Date to

        </label>


        <input
            id="date_to"
            name="date_to"
            type="date"
            value="<?= h($dateTo) ?>"
        >

    </div>



    <!-- SEARCH -->

    <div class="field">

        <label for="q">

            Search

        </label>


        <input
            id="q"
            name="q"
            type="search"

            placeholder="Employee, client or task"

            value="<?= h($search) ?>"
        >

    </div>



    <button
        class="primary"
        type="submit"
    >

        Apply Filters

    </button>



    <a
        class="button secondary"
        href="admin-all-work.php"
    >

        Clear

    </a>


</form>



<!-- =========================================================
     STATS
========================================================= -->

<div class="stats-grid">


    <!-- TOTAL -->

    <article class="stat-card">

        <h2>
            Total assigned
        </h2>

        <strong>
            <?= $totalTasks ?>
        </strong>

        <p>
            Tasks assigned by admin
        </p>

    </article>



    <!-- SUBMITTED -->

    <article class="stat-card">

        <h2>
            Employee submitted
        </h2>

        <strong>
            <?= $submittedTasks ?>
        </strong>

        <p>
            Tasks updated by employees
        </p>

    </article>



    <!-- COMPLETED -->

    <article class="stat-card">

        <h2>
            Completed
        </h2>

        <strong>
            <?= $completedTasks ?>
        </strong>

        <p>
            Completed assigned tasks
        </p>

    </article>



    <!-- PENDING -->

    <article class="stat-card">

        <h2>
            Pending
        </h2>

        <strong>
            <?= $pendingTasks ?>
        </strong>

        <p>
            Work still pending
        </p>

    </article>


</div>



<!-- =========================================================
     ALL WORK TABLE
========================================================= -->

<section class="panel">


    <div class="panel-heading">


        <h2>

            Assigned Work

        </h2>


        <span class="muted">

            <?= $totalTasks ?>
            task<?= $totalTasks === 1 ? '' : 's' ?>

        </span>


    </div>



    <div class="table-scroll">


        <table>


            <thead>


                <tr>

                    <th>
                        Date
                    </th>

                    <th>
                        Employee
                    </th>

                    <th>
                        Department
                    </th>

                    <th>
                        Client
                    </th>

                    <th>
                        Task
                    </th>

                    <th>
                        Submission
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Time spent
                    </th>

                    <th>
                        Employee remark
                    </th>

                    <th>
                        Assigned by
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>


            </thead>



            <tbody>


                <?php


                if (!$assignments) {

                    empty_row(
                        11,
                        'No assigned work found.'
                    );

                }



                foreach (
                    $assignments
                    as $assignment
                ):


                    /* =========================================
                       EFFECTIVE STATUS
                    ========================================= */

                    $effectiveStatus =

                        $assignment[
                            'employee_status'
                        ]

                        ?:

                        $assignment[
                            'assignment_status'
                        ]

                        ?:

                        'pending';



                    /* =========================================
                       EFFECTIVE TIME
                    ========================================= */

                    $timeSpent =

                        $assignment[
                            'employee_time'
                        ]

                        !== null

                            ? $assignment[
                                'employee_time'
                            ]

                            : $assignment[
                                'assignment_time'
                            ];



                    /* =========================================
                       EFFECTIVE REMARK
                    ========================================= */

                    $employeeRemark =

                        $assignment[
                            'employee_remark'
                        ]

                        ?:

                        $assignment[
                            'assignment_remark'
                        ]

                        ?:

                        '';

                ?>


                    <tr>


                        <!-- DATE -->

                        <td>

                            <?= h(
                                $assignment[
                                    'work_date'
                                ]
                            ) ?>

                        </td>



                        <!-- EMPLOYEE -->

                        <td>

                            <strong>

                                <?= h(
                                    $assignment[
                                        'employee_name'
                                    ]
                                ) ?>

                            </strong>

                        </td>



                        <!-- DEPARTMENT -->

                        <td>

                            <?= h(
                                $assignment[
                                    'department_name'
                                ]
                            ) ?>

                        </td>



                        <!-- CLIENT -->

                        <td>

                            <?= h(
                                $assignment[
                                    'client_name'
                                ]
                                ?: 'Internal work'
                            ) ?>

                        </td>



                        <!-- TASK -->

                        <td class="wrap">


                            <div class="admin-work-task-title">

                                Task

                                <?= $taskNumbers[
                                    $assignment[
                                        'assignment_id'
                                    ]
                                ] ?>

                                ·

                                <?= h(
                                    $assignment[
                                        'title'
                                    ]
                                ) ?>

                            </div>


                            <?php if (
                                !empty(
                                    $assignment[
                                        'description'
                                    ]
                                )
                            ): ?>


                                <div class="admin-work-task-brief">

                                    <?= h(
                                        $assignment[
                                            'description'
                                        ]
                                    ) ?>

                                </div>


                            <?php endif; ?>


                        </td>



                        <!-- SUBMISSION -->

                        <td>


                            <?php if (
                                !empty(
                                    $assignment[
                                        'entry_id'
                                    ]
                                )
                            ): ?>


                                <span class="admin-work-submitted">

                                    Submitted

                                </span>


                                <?php if (
                                    !empty(
                                        $assignment[
                                            'submitted_work_date'
                                        ]
                                    )
                                ): ?>


                                    <div
                                        class="muted"
                                        style="
                                            margin-top:5px;
                                            font-size:11px;
                                        "
                                    >

                                        <?= h(
                                            $assignment[
                                                'submitted_work_date'
                                            ]
                                        ) ?>

                                    </div>


                                <?php endif; ?>


                            <?php else: ?>


                                <span class="admin-work-not-submitted">

                                    Not submitted

                                </span>


                            <?php endif; ?>


                        </td>



                        <!-- STATUS -->

                        <td>

                            <?= status_badge(
                                $effectiveStatus
                            ) ?>

                        </td>



                        <!-- TIME -->

                        <td>


                            <?php if (
                                $timeSpent !== null
                            ): ?>


                                <?= h(
                                    format_work_duration(
                                        $timeSpent
                                    )
                                ) ?>


                            <?php else: ?>


                                <span class="muted">

                                    —

                                </span>


                            <?php endif; ?>


                        </td>



                        <!-- REMARK -->

                        <td class="wrap">


                            <?php if (
                                $employeeRemark !== ''
                            ): ?>


                                <?= h(
                                    $employeeRemark
                                ) ?>


                            <?php else: ?>


                                <span class="muted">

                                    No update

                                </span>


                            <?php endif; ?>


                        </td>



                        <!-- ASSIGNED BY -->

                        <td>

                            <?= h(
                                $assignment[
                                    'assigned_by_name'
                                ]
                            ) ?>

                        </td>



                        <!-- ACTIONS -->

                        <td>


                            <div class="actions">


                                <?php

                                detail_button(
                                    'admin-work-' .
                                    $assignment[
                                        'assignment_id'
                                    ]
                                );

                                ?>


                                <a
                                    class="icon-button"

                                    href="manager-assign-work.php?edit=<?= (int) $assignment['assignment_id'] ?>"

                                    title="Edit assignment"

                                    aria-label="Edit assignment"
                                >

                                    <?= icon(
                                        'edit'
                                    ) ?>

                                </a>


                            </div>


                        </td>


                    </tr>


                <?php endforeach; ?>


            </tbody>


        </table>


    </div>


</section>



<?php
/* =========================================================
   DETAIL DIALOGS
========================================================= */

foreach (
    $assignments
    as $assignment
) {


    $effectiveStatus =

        $assignment[
            'employee_status'
        ]

        ?:

        $assignment[
            'assignment_status'
        ]

        ?:

        'pending';



    $timeSpent =

        $assignment[
            'employee_time'
        ]

        !== null

            ? $assignment[
                'employee_time'
            ]

            : $assignment[
                'assignment_time'
            ];



    $employeeRemark =

        $assignment[
            'employee_remark'
        ]

        ?:

        $assignment[
            'assignment_remark'
        ]

        ?:

        '';



    detail_dialog(

        'admin-work-' .
        $assignment[
            'assignment_id'
        ],


        'Task ' .
        $taskNumbers[
            $assignment[
                'assignment_id'
            ]
        ]
        .
        ' · '
        .
        $assignment[
            'title'
        ],


        [

            'Employee' =>

                $assignment[
                    'employee_name'
                ],


            'Department' =>

                $assignment[
                    'department_name'
                ],


            'Client' =>

                $assignment[
                    'client_name'
                ]
                ?: 'Internal work',


            'Work date' =>

                $assignment[
                    'work_date'
                ],


            'Task' =>

                $assignment[
                    'title'
                ],


            'Task brief' =>

                $assignment[
                    'description'
                ],


            'Assigned by' =>

                $assignment[
                    'assigned_by_name'
                ],


            'Employee submitted' =>

                $assignment[
                    'entry_id'
                ]

                    ? 'Yes'

                    : 'No',


            'Submitted work date' =>

                $assignment[
                    'submitted_work_date'
                ]
                ?: 'Not submitted',


            'Status' =>

                ucfirst(
                    $effectiveStatus
                ),


            'Time spent' =>

                $timeSpent !== null

                    ? format_work_duration(
                        $timeSpent
                    )

                    : 'Not submitted',


            'Employee remark / issue' =>

                $employeeRemark
                ?: 'No remark',


            'Submission status' =>

                $assignment[
                    'submission_status'
                ]
                ?: 'Not submitted',


            'Review status' =>

                $assignment[
                    'review_status'
                ]
                ?: 'Pending'

        ]

    );

}



page_end();

?>