<?php include 'header.php'; ?>

<section class="telecaller-employee-dashboard">
  <!-- Employee sidebar -->
  <aside
    class="ted-sidebar offcanvas-lg offcanvas-start"
    id="telecallerEmployeeSidebar"
    tabindex="-1"
    aria-labelledby="telecallerEmployeeSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="telecallerEmployeeSidebarTitle">
        Employee menu
      </h5>

      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#telecallerEmployeeSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="ted-sidebar-body">
      <a href="employee-dashboard.php" class="ted-brand">
        <span class="ted-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="ted-profile">
        <div class="ted-avatar">N</div>
        <h2>Employee</h2>
        <p>Neha</p>
      </div>

      <nav class="ted-navigation" aria-label="Employee navigation">
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

      <div class="ted-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="ted-main">
    <header class="ted-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="ted-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#telecallerEmployeeSidebar"
          aria-controls="telecallerEmployeeSidebar"
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

        <div class="ted-breadcrumb">
          Employee <span>/</span> My work
        </div>
      </div>

      <div class="ted-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="ted-role-badge">Employee</span>
      </div>
    </header>

    <div class="ted-content">
      <div class="ted-heading">
        <h1>Telecaller dashboard</h1>
        <p>
          Welcome, Neha. Record your daily work and keep your team updated.
        </p>
      </div>

      <!-- Summary cards -->
      <div class="row ted-summary-row">
        <div class="col-12 col-md-4">
          <article class="ted-summary-card">
            <h2>Today's work</h2>
            <strong>2 entries</strong>
            <p>Date and serial number are automatic</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="ted-summary-card">
            <h2>Leave status</h2>
            <strong>Pending</strong>
            <p>Personal leave - 05 Oct</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="ted-summary-card">
            <h2>Next holiday</h2>
            <strong>20 Oct</strong>
            <p>Vijayadashami</p>
          </article>
        </div>
      </div>

      <!-- Daily work sheet -->
      <article class="ted-work-card">
        <div class="ted-work-heading">
          <div>
            <h2>Daily work sheet</h2>
            <p>
              Enter work quantities and remarks. Each row belongs to one client.
            </p>
          </div>

          <span class="ted-date-badge">03 Oct 2026</span>
        </div>

        <div class="ted-table-card">
          <div
            class="table-responsive"
            role="region"
            aria-label="Telecaller daily work sheet"
            tabindex="0"
          >
            <table class="table ted-table">
              <thead>
                <tr>
                  <th scope="col">S.no</th>
                  <th scope="col">Date</th>
                  <th scope="col">Client</th>
                  <th scope="col">Calls</th>
                  <th scope="col">Connected</th>
                  <th scope="col">Follow-ups</th>
                  <th scope="col">Leads</th>
                  <th scope="col">Remark</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td>01</td>
                  <td><time datetime="2026-10-03">03 Oct</time></td>
                  <td>Krishna Dental</td>
                  <td>30</td>
                  <td>22</td>
                  <td>8</td>
                  <td>3</td>
                  <td>3 interested</td>
                </tr>

                <tr>
                  <td>02</td>
                  <td><time datetime="2026-10-03">03 Oct</time></td>
                  <td>V&amp;V Salon</td>
                  <td>25</td>
                  <td>18</td>
                  <td>7</td>
                  <td>2</td>
                  <td>Call tomorrow</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ted-add-row">
          <button type="button" class="ted-add-button">
            + Add work row
          </button>
          <span>S.no and date are read-only</span>
        </div>

        <div class="ted-submit-row">
          <p>Draft saved <span>/</span> 2 entries</p>
          <button type="button" class="ted-submit-button">
            Submit to Sir
          </button>
        </div>
      </article>

      <nav class="ted-quick-access" aria-label="Quick access">
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

    <footer class="ted-footer">
      <span>Bhavi Creations / Employee / UI/UX concept - sample data</span>
      <span>13 / Desktop</span>
    </footer>
  </div>
</section>


<?php include 'footer.php'; ?>