<?php include 'header.php'; ?>

<section class="admin-dashboard">
  <!-- Sidebar -->
  <aside
    class="ad-sidebar offcanvas-lg offcanvas-start"
    id="adminSidebar"
    tabindex="-1"
    aria-labelledby="adminSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 id="adminSidebarTitle" class="offcanvas-title">Admin menu</h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#adminSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="ad-sidebar-body">
      <a href="admin-dashboard.php" class="ad-logo">
        <span class="ad-logo-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="ad-profile">
        <div class="ad-profile-circle">A</div>
        <h2>Admin</h2>
        <p>Account administrator</p>
      </div>

      <nav class="ad-menu" aria-label="Admin navigation">
        <a
          href="admin-dashboard.php"
          class="active"
          aria-current="page"
        >
          <span></span>Dashboard
        </a>

        <a href="admin-employees.php">
          <span></span>Employees
        </a>

        <a href="admin-add-employee.php">
          <span></span>Add employee
        </a>

        <a href="admin-holidays.php">
          <span></span>Holidays
        </a>
      </nav>

      <div class="ad-workspace-box">
        <strong>Admin workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main workspace -->
  <div class="ad-main">
    <header class="ad-header">
      <div class="ad-header-left">
        <button
          class="ad-mobile-toggle d-lg-none"
          type="button"
          data-bs-toggle="offcanvas"
          data-bs-target="#adminSidebar"
          aria-controls="adminSidebar"
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

        <div class="ad-breadcrumb">
          Admin <span>/</span> Dashboard
        </div>
      </div>

      <div class="ad-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="ad-admin-badge">Admin</span>
      </div>
    </header>

    <div class="ad-content">
      <div class="ad-title">
        <h1>Admin dashboard</h1>
        <p>
          Manage employee accounts, department access and company holidays.
        </p>
      </div>

      <!-- Statistics -->
      <div class="row ad-stats-row">
        <div class="col-12 col-md-4">
          <article class="ad-stat-box">
            <h2>Total employees</h2>
            <strong>10</strong>
            <p>Updates after an account is created</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="ad-stat-box">
            <h2>Active accounts</h2>
            <strong>10</strong>
            <p>Individual usernames and passwords</p>
          </article>
        </div>

        <div class="col-12 col-md-4">
          <article class="ad-stat-box">
            <h2>Departments</h2>
            <strong>5</strong>
            <p>Role-specific employee dashboards</p>
          </article>
        </div>
      </div>

      <!-- Account management -->
      <article class="ad-account-box">
        <h2>Employee account management</h2>
        <p class="ad-account-description">
          Create an individual account and assign the employee department.
        </p>

        <div class="ad-buttons">
          <a href="admin-add-employee.php" class="ad-add-button">
            Add employee
          </a>
          <a href="admin-employees.php" class="ad-view-button">
            View employees
          </a>
        </div>

        <div class="ad-recent">
          <h3>Recent account</h3>

          <div class="ad-employee-row">
            <h4>Ananya Kumar</h4>
            <p>Design &amp; video</p>
            <div class="ad-status-column">
              <span class="ad-active-status">Active</span>
            </div>
          </div>
        </div>
      </article>

      <div class="ad-info-banner">
        Employee account creation is available only in the Admin workspace.
      </div>
    </div>

    <footer class="ad-footer">
      <span>Bhavi Creations / Admin / UI/UX concept - sample data</span>
      <span>06 / Desktop</span>
    </footer>
  </div>
</section>

<?php include 'footer.php'; ?>