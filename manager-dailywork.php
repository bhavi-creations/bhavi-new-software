

<?php include 'header.php'; ?>

<section class="manager-dailywork">

  <!-- Sidebar -->
  <aside
    class="mdw-sidebar offcanvas-lg offcanvas-start"
    id="managerDailyworkSidebar"
    tabindex="-1"
    aria-labelledby="managerDailyworkSidebarTitle"
  >
    <button
      type="button"
      class="btn-close btn-close-white mdw-close d-lg-none"
      data-bs-dismiss="offcanvas"
      data-bs-target="#managerDailyworkSidebar"
      aria-label="Close navigation"
    ></button>

    <div class="mdw-sidebar-inner">

      <a href="manager-dashboard.php" class="mdw-brand">
        <span class="mdw-brand-icon">B</span>

        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="mdw-profile">
        <div class="mdw-avatar" aria-hidden="true">S</div>
        <h2 id="managerDailyworkSidebarTitle">Manager</h2>
        <p>Sir</p>
      </div>

      <nav class="mdw-navigation" aria-label="Manager navigation">

        <a href="manager-dashboard.php">
          <span class="mdw-dot"></span>
          Dashboard
        </a>

        <a href="manager-dashboard.php?view=team">
          <span class="mdw-dot"></span>
          Team overview
        </a>

        <a
          href="manager-dashboard.php?view=work"
          class="active"
          aria-current="page"
        >
          <span class="mdw-dot"></span>
          Daily work
        </a>

        <a href="manager-dashboard.php?view=leave">
          <span class="mdw-dot"></span>
          Leave requests
        </a>

        <a href="manager-dashboard.php?view=holidays">
          <span class="mdw-dot"></span>
          Holidays
        </a>

        <a href="manager-dashboard.php?view=notifications">
          <span class="mdw-dot"></span>
          Notifications
        </a>

      </nav>

      <div class="mdw-workspace">
        <strong>Manager workspace</strong>
        <button type="button" class="mdw-signout">Sign out</button>
      </div>

    </div>
  </aside>

  <!-- Main content -->
  <div class="mdw-main">

    <header class="mdw-header">

      <button
        type="button"
        class="mdw-menu-button d-lg-none"
        data-bs-toggle="offcanvas"
        data-bs-target="#managerDailyworkSidebar"
        aria-controls="managerDailyworkSidebar"
        aria-label="Open navigation"
      >
        <span></span>
        <span></span>
        <span></span>
      </button>

      <div class="mdw-breadcrumb">
        Manager <span>/</span> Daily work
      </div>

      <time class="mdw-header-date" datetime="2026-10-03">
        03 October 2026
      </time>

      <span class="mdw-role">Manager</span>

    </header>

    <main class="mdw-content">

      <div class="mdw-heading">
        <h1>Daily work report</h1>
        <p>Filter submissions by date, department or employee.</p>
      </div>

      <!-- Filters -->
      <div class="row g-3 mdw-filters">

        <div class="col-12 col-sm-6 col-xl-3">
          <label for="mdwDate" class="form-label">Date</label>

          <input
            id="mdwDate"
            name="date"
            type="date"
            class="form-control"
            value="2026-10-03"
          >
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
          <label for="mdwDepartment" class="form-label">
            Department
          </label>

          <select
            id="mdwDepartment"
            name="department"
            class="form-select"
          >
            <option value="">All departments</option>
            <option value="design">Design</option>
            <option value="website">Website</option>
            <option value="seo">SEO</option>
            <option value="telecaller">Telecaller</option>
            <option value="social-media">Social media</option>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
          <label for="mdwEmployee" class="form-label">
            Employee
          </label>

          <select
            id="mdwEmployee"
            name="employee"
            class="form-select"
          >
            <option value="">All employees</option>
            <option value="ananya">Ananya Kumar</option>
            <option value="ravi">Ravi Teja</option>
            <option value="rahul">Rahul Varma</option>
            <option value="priya">Priya Rao</option>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-xl-3 mdw-download-column">
          <button type="button" class="btn mdw-download-button">
            Download Excel
          </button>
        </div>

      </div>

      <!-- Summary cards -->
      <div class="row g-3 mdw-summary">

        <div class="col-12 col-sm-4">
          <article class="mdw-summary-card">
            <h2>Submissions</h2>
            <strong>8</strong>
            <p>From 10 employees</p>
          </article>
        </div>

        <div class="col-12 col-sm-4">
          <article class="mdw-summary-card">
            <h2>Completed tasks</h2>
            <strong>26</strong>
            <p>Across all departments</p>
          </article>
        </div>

        <div class="col-12 col-sm-4">
          <article class="mdw-summary-card">
            <h2>Revisions</h2>
            <strong>4</strong>
            <p>Included in daily entries</p>
          </article>
        </div>

      </div>

      <!-- Daily work table -->
      <div class="mdw-table-wrapper">

        <div
          class="table-responsive"
          tabindex="0"
          role="region"
          aria-label="Daily work submissions"
        >
          <table class="table mdw-table">

            <thead>
              <tr>
                <th scope="col">Employee</th>
                <th scope="col">Department</th>
                <th scope="col">Work summary</th>
                <th scope="col">Submitted</th>
                <th scope="col">Review</th>
              </tr>
            </thead>

            <tbody>

              <tr>
                <td>Ananya Kumar</td>
                <td>Design</td>
                <td>2 posters, 1 carousel</td>
                <td>06:20 PM</td>
                <td>
                  <button type="button" class="mdw-details-button">
                    View details
                  </button>
                </td>
              </tr>

              <tr>
                <td>Ravi Teja</td>
                <td>Website</td>
                <td>2 pages, 1 revision</td>
                <td>06:10 PM</td>
                <td>
                  <button type="button" class="mdw-details-button">
                    View details
                  </button>
                </td>
              </tr>

              <tr>
                <td>Rahul Varma</td>
                <td>SEO</td>
                <td>4 tasks completed</td>
                <td>05:55 PM</td>
                <td>
                  <button type="button" class="mdw-details-button">
                    View details
                  </button>
                </td>
              </tr>

              <tr>
                <td>Priya Rao</td>
                <td>Social media</td>
                <td>3 posts, 1 reel</td>
                <td>05:40 PM</td>
                <td>
                  <button type="button" class="mdw-details-button">
                    View details
                  </button>
                </td>
              </tr>

            </tbody>

          </table>
        </div>

      </div>

      <p class="mdw-export-note">
        Export includes the selected day, employee details and
        department-specific work columns.
      </p>

      <footer class="mdw-footer">
        <span>
          Bhavi Creations / Manager / UI/UX concept - sample data
        </span>

        <span>02 / Desktop</span>
      </footer>

    </main>

  </div>

</section>

<?php include 'footer.php'; ?>