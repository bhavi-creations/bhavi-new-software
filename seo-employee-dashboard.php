<?php include 'header.php'; ?>

<section class="seo-employee-dashboard">
  <!-- Employee sidebar -->
  <aside
    class="sed-sidebar offcanvas-lg offcanvas-start"
    id="seoEmployeeSidebar"
    tabindex="-1"
    aria-labelledby="seoEmployeeSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="seoEmployeeSidebarTitle">
        Employee menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#seoEmployeeSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="sed-sidebar-body">
      <a href="employee-dashboard.php" class="sed-brand">
        <span class="sed-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="sed-profile">
        <div class="sed-avatar">R</div>
        <h2>Employee</h2>
        <p>Rahul</p>
      </div>

      <nav class="sed-navigation" aria-label="Employee navigation">
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

      <div class="sed-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="sed-main">
    <header class="sed-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="sed-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#seoEmployeeSidebar"
          aria-controls="seoEmployeeSidebar"
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

        <div class="sed-breadcrumb">
          Employee <span>/</span> My work
        </div>
      </div>

      <div class="sed-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="sed-role-badge">Employee</span>
      </div>
    </header>

    <div class="sed-content">
      <div class="sed-heading">
        <h1>SEO dashboard</h1>
        <p>
          Welcome, Rahul. Record your daily work and keep your team updated.
        </p>
      </div>

      <!-- Summary cards -->
      <div class="row sed-summary-row">
        <div class="col-12 col-md-4">
          <article class="sed-summary-card">
            <h2>Today's work</h2>
            <strong>2 entries</strong>
            <p>Date and serial number are automatic</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="sed-summary-card">
            <h2>Leave status</h2>
            <strong>Pending</strong>
            <p>Personal leave - 05 Oct</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="sed-summary-card">
            <h2>Next holiday</h2>
            <strong>20 Oct</strong>
            <p>Vijayadashami</p>
          </article>
        </div>
      </div>

      <!-- Daily work sheet -->
      <article class="sed-work-card">
        <div class="sed-work-heading">
          <div>
            <h2>Daily work sheet</h2>
            <p>
              Enter work quantities and remarks. Each row belongs to one client.
            </p>
          </div>

          <span class="sed-date-badge">03 Oct 2026</span>
        </div>

        <div class="sed-table-card">
          <div
            class="table-responsive"
            role="region"
            aria-label="SEO daily work sheet"
            tabindex="0"
          >
            <table class="table sed-table">
              <thead>
                <tr>
                  <th scope="col">S.no</th>
                  <th scope="col">Date</th>
                  <th scope="col">Client</th>
                  <th scope="col">Task</th>
                  <th scope="col">Quantity</th>
                  <th scope="col">Status</th>
                  <th scope="col">Remark</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td>01</td>
                  <td><time datetime="2026-10-03">03 Oct</time></td>
                  <td>Krishna Dental</td>
                  <td>On-page SEO</td>
                  <td>3 pages</td>
                  <td>Completed</td>
                  <td>Meta updates</td>
                </tr>

                <tr>
                  <td>02</td>
                  <td><time datetime="2026-10-03">03 Oct</time></td>
                  <td>Vision Dental</td>
                  <td>Business profile</td>
                  <td>1 post</td>
                  <td>Completed</td>
                  <td>Published</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="sed-add-row">
          <button type="button" class="sed-add-button">
            + Add work row
          </button>
          <span>S.no and date are read-only</span>
        </div>

        <div class="sed-submit-row">
          <p>Draft saved <span>/</span> 2 entries</p>
          <button type="button" class="sed-submit-button">
            Submit to Sir
          </button>
        </div>
      </article>

      <nav class="sed-quick-access" aria-label="Quick access">
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

    <footer class="sed-footer">
      <span>Bhavi Creations / Employee / UI/UX concept - sample data</span>
      <span>12 / Desktop</span>
    </footer>
  </div>
</section>

<?php include 'footer.php'; ?>