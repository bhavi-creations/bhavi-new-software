<?php include 'header.php'; ?>


<section class="weebsite-employee-dashboard">
  <!-- Employee sidebar -->
  <aside
    class="wed-sidebar offcanvas-lg offcanvas-start"
    id="websiteEmployeeSidebar"
    tabindex="-1"
    aria-labelledby="websiteEmployeeSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="websiteEmployeeSidebarTitle">
        Employee menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#websiteEmployeeSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="wed-sidebar-body">
      <a href="employee-dashboard.php" class="wed-brand">
        <span class="wed-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="wed-profile">
        <div class="wed-avatar">R</div>
        <h2>Employee</h2>
        <p>Ravi</p>
      </div>

      <nav class="wed-navigation" aria-label="Employee navigation">
        <a href="employee-dashboard.php">
          <span></span>Dashboard
        </a>
        <a
          href="employee-my-work.php"
          class="active"
          aria-current="page"
        >
          <span></span>My work
        </a>
        <a href="employee-brand-assets.php">
          <span></span>Brand assets
        </a>
        <a href="employee-client-requirements.php">
          <span></span>Client requirements
        </a>
        <a href="employee-apply-leave.php">
          <span></span>Apply leave
        </a>
        <a href="employee-holidays.php">
          <span></span>Holidays
        </a>
      </nav>

      <div class="wed-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="wed-main">
    <header class="wed-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="wed-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#websiteEmployeeSidebar"
          aria-controls="websiteEmployeeSidebar"
          aria-label="Open menu"
        >
          <svg
            width="22"
            height="22"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path d="M4 6h16M4 12h16M4 18h16"></path>
          </svg>
        </button>

        <div class="wed-breadcrumb">
          Employee <span>/</span> My work
        </div>
      </div>

      <div class="wed-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="wed-role-badge">Employee</span>
      </div>
    </header>

    <div class="wed-content">
      <div class="wed-heading">
        <h1>Website dashboard</h1>
        <p>Welcome, Ravi. Record your daily work and keep your team updated.</p>
      </div>

      <!-- Summary cards -->
      <div class="row wed-summary-row">
        <div class="col-12 col-md-4">
          <article class="wed-summary-card">
            <h2>Today's work</h2>
            <strong>2 entries</strong>
            <p>Date and serial number are automatic</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="wed-summary-card">
            <h2>Leave status</h2>
            <strong>Pending</strong>
            <p>Personal leave - 05 Oct</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="wed-summary-card">
            <h2>Next holiday</h2>
            <strong>20 Oct</strong>
            <p>Vijayadashami</p>
          </article>
        </div>
      </div>

      <!-- Daily work sheet -->
      <article class="wed-work-card">
        <div class="wed-work-heading">
          <div>
            <h2>Daily work sheet</h2>
            <p>
              Enter work quantities and remarks. Each row belongs to one client.
            </p>
          </div>
          <span class="wed-date-badge">03 Oct 2026</span>
        </div>

        <div class="wed-table-card">
          <div
            class="table-responsive"
            role="region"
            aria-label="Website daily work sheet"
            tabindex="0"
          >
            <table class="table wed-table">
              <thead>
                <tr>
                  <th scope="col">S.no</th>
                  <th scope="col">Date</th>
                  <th scope="col">Client</th>
                  <th scope="col">Page / task</th>
                  <th scope="col">New</th>
                  <th scope="col">Changes</th>
                  <th scope="col">Status</th>
                  <th scope="col">Remark</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td>01</td>
                  <td><time datetime="2026-10-03">03 Oct</time></td>
                  <td>Vision Dental</td>
                  <td>Header section</td>
                  <td>1</td>
                  <td>0</td>
                  <td>Completed</td>
                  <td>Responsive</td>
                </tr>

                <tr>
                  <td>02</td>
                  <td><time datetime="2026-10-03">03 Oct</time></td>
                  <td>Bhavi</td>
                  <td>Contact page</td>
                  <td>1</td>
                  <td>1</td>
                  <td>In progress</td>
                  <td>Form layout</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="wed-add-row">
          <button type="button" class="wed-add-button">
            + Add work row
          </button>
          <span>S.no and date are read-only</span>
        </div>

        <div class="wed-submit-row">
          <p>Draft saved <span>/</span> 2 entries</p>
          <button type="button" class="wed-submit-button">
            Submit to Sir
          </button>
        </div>
      </article>

      <nav class="wed-quick-access" aria-label="Quick access">
        <span>Quick access:</span>
        <a href="employee-brand-assets.php">Brand assets</a>
        <span>/</span>
        <a href="employee-client-requirements.php">Client requirements</a>
        <span>/</span>
        <a href="employee-apply-leave.php">Apply leave</a>
        <span>/</span>
        <a href="employee-holidays.php">Holidays</a>
      </nav>
    </div>

    <footer class="wed-footer">
      <span>Bhavi Creations / Employee / UI/UX concept - sample data</span>
      <span>11 / Desktop</span>
    </footer>
  </div>
</section>


<?php include 'footer.php'; ?>