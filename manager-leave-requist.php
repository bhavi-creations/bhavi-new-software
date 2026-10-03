<?php include 'header.php'; ?>

<section class="manager-leave-requist">

  <!-- Sidebar -->
  <aside
    class="mlr-sidebar offcanvas-lg offcanvas-start"
    id="managerLeaveSidebar"
    tabindex="-1"
    aria-labelledby="managerLeaveSidebarTitle"
  >
    <button
      type="button"
      class="btn-close btn-close-white mlr-close d-lg-none"
      data-bs-dismiss="offcanvas"
      data-bs-target="#managerLeaveSidebar"
      aria-label="Close navigation"
    ></button>

    <div class="mlr-sidebar-inner">

      <a href="manager-dashboard.php" class="mlr-brand">
        <span class="mlr-brand-icon">B</span>

        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="mlr-profile">
        <div class="mlr-avatar" aria-hidden="true">S</div>
        <h2 id="managerLeaveSidebarTitle">Manager</h2>
        <p>Sir</p>
      </div>

      <nav class="mlr-navigation" aria-label="Manager navigation">

        <a href="manager-dashboard.php">
          <span class="mlr-dot"></span>
          Dashboard
        </a>

        <a href="manager-dashboard.php?view=team">
          <span class="mlr-dot"></span>
          Team overview
        </a>

        <a href="manager-dashboard.php?view=work">
          <span class="mlr-dot"></span>
          Daily work
        </a>

        <a
          href="manager-dashboard.php?view=leave"
          class="active"
          aria-current="page"
        >
          <span class="mlr-dot"></span>
          Leave requests
        </a>

        <a href="manager-dashboard.php?view=holidays">
          <span class="mlr-dot"></span>
          Holidays
        </a>

        <a href="manager-dashboard.php?view=notifications">
          <span class="mlr-dot"></span>
          Notifications
        </a>

      </nav>

      <div class="mlr-workspace">
        <strong>Manager workspace</strong>
        <button type="button" class="mlr-signout">Sign out</button>
      </div>

    </div>
  </aside>

  <!-- Main area -->
  <div class="mlr-main">

    <header class="mlr-header">

      <button
        type="button"
        class="mlr-menu-button d-lg-none"
        data-bs-toggle="offcanvas"
        data-bs-target="#managerLeaveSidebar"
        aria-controls="managerLeaveSidebar"
        aria-label="Open navigation"
      >
        <span></span>
        <span></span>
        <span></span>
      </button>

      <div class="mlr-breadcrumb">
        Manager <span>/</span> Leave requests
      </div>

      <time class="mlr-header-date" datetime="2026-10-03">
        03 October 2026
      </time>

      <span class="mlr-role">Manager</span>

    </header>

    <main class="mlr-content">

      <div class="mlr-heading">
        <h1>Leave requests</h1>
        <p>Review employee leave applications and record your decision.</p>
      </div>

      <!-- Summary cards -->
      <div class="row g-3 mlr-summary">

        <div class="col-12 col-sm-4">
          <article class="mlr-summary-card">
            <h2>Pending requests</h2>
            <strong>2</strong>
            <p>Awaiting your decision</p>
          </article>
        </div>

        <div class="col-12 col-sm-4">
          <article class="mlr-summary-card">
            <h2>Approved this month</h2>
            <strong>4</strong>
            <p>Across all departments</p>
          </article>
        </div>

        <div class="col-12 col-sm-4">
          <article class="mlr-summary-card">
            <h2>On leave today</h2>
            <strong>0</strong>
            <p>All team members available</p>
          </article>
        </div>

      </div>

      <!-- Leave requests table -->
      <div class="mlr-table-wrapper">
        <div
          class="table-responsive"
          tabindex="0"
          role="region"
          aria-label="Employee leave requests"
        >
          <table class="table mlr-table">

            <thead>
              <tr>
                <th scope="col">Employee</th>
                <th scope="col">Department</th>
                <th scope="col">Dates</th>
                <th scope="col">Reason</th>
                <th scope="col">Status</th>
              </tr>
            </thead>

            <tbody>
              <tr>
                <td>Ananya Kumar</td>
                <td>Design</td>
                <td>05 Oct - 05 Oct</td>
                <td>Personal commitment</td>
                <td>Pending</td>
              </tr>

              <tr>
                <td>Rahul Varma</td>
                <td>SEO</td>
                <td>07 Oct - 08 Oct</td>
                <td>Family function</td>
                <td>Pending</td>
              </tr>
            </tbody>

          </table>
        </div>
      </div>

      <!-- Selected leave request -->
      <section class="mlr-decision-card" aria-labelledby="mlrDecisionTitle">

        <h2 id="mlrDecisionTitle">
          Ananya Kumar <span>/</span> 1 day
        </h2>

        <p class="mlr-leave-description">
          Personal leave - 05 October 2026
        </p>

        <div class="row g-3 align-items-end">

          <div class="col-12 col-xl-7">
            <label for="mlrDecisionNote" class="form-label">
              Decision note
            </label>

            <input
              type="text"
              id="mlrDecisionNote"
              name="decision_note"
              class="form-control"
              placeholder="Optional note for employee"
              maxlength="500"
            >
          </div>

          <div class="col-12 col-xl-5">
            <div class="mlr-decision-actions">

              <button type="button" class="btn mlr-approve-button">
                Approve
              </button>

              <button type="button" class="btn mlr-reject-button">
                Reject
              </button>

            </div>
          </div>

        </div>

      </section>

      <footer class="mlr-footer">
        <span>
          Bhavi Creations / Manager / UI/UX concept - sample data
        </span>

        <span>04 / Desktop</span>
      </footer>

    </main>

  </div>

</section>




<?php include 'footer.php'; ?>