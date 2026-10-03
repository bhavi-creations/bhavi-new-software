<?php include 'header.php'; ?>
<section class="apply-leaves">
  <!-- Employee sidebar -->
  <aside
    class="al-sidebar offcanvas-lg offcanvas-start"
    id="applyLeavesSidebar"
    tabindex="-1"
    aria-labelledby="applyLeavesSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="applyLeavesSidebarTitle">
        Employee menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#applyLeavesSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="al-sidebar-body">
      <a href="employee-dashboard.php" class="al-brand">
        <span class="al-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="al-profile">
        <div class="al-avatar">A</div>
        <h2>Employee</h2>
        <p>Ananya Kumar</p>
      </div>

      <nav class="al-navigation" aria-label="Employee navigation">
        <a href="employee-dashboard.php">
          <span></span>Dashboard
        </a>
        <a href="employee-my-work.php">
          <span></span>My work
        </a>
        <a href="employee-brand-assets.php">
          <span></span>Brand assets
        </a>
        <a href="employee-client-requirements.php">
          <span></span>Client requirements
        </a>
        <a
          href="employee-apply-leave.php"
          class="active"
          aria-current="page"
        >
          <span></span>Apply leave
        </a>
        <a href="employee-holidays.php">
          <span></span>Holidays
        </a>
      </nav>

      <div class="al-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="al-main">
    <header class="al-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="al-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#applyLeavesSidebar"
          aria-controls="applyLeavesSidebar"
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

        <div class="al-breadcrumb">
          Employee <span>/</span> Apply leave
        </div>
      </div>

      <div class="al-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="al-role-badge">Employee</span>
      </div>
    </header>

    <div class="al-content">
      <div class="al-heading">
        <h1>Apply leave</h1>
        <p>Send a leave request and track its approval status.</p>
      </div>

      <div class="row al-panels">
        <!-- New leave request -->
        <div class="col-12 col-xl-8">
          <article class="al-form-card">
            <h2>New leave request</h2>

            <form
              class="al-form"
              action="employee-submit-leave.php"
              method="post"
            >
              <!-- Add your backend CSRF token here -->

              <div class="al-field">
                <label for="alLeaveType">Leave type</label>
                <select
                  id="alLeaveType"
                  name="leave_type"
                  class="form-select al-input al-select"
                  required
                >
                  <option value="personal">Personal leave</option>
                  <option value="sick">Sick leave</option>
                  <option value="casual">Casual leave</option>
                  <option value="other">Other</option>
                </select>
              </div>

              <div class="row al-date-row">
                <div class="col-12 col-md-6">
                  <div class="al-field">
                    <label for="alFromDate">From date</label>
                    <input
                      type="date"
                      id="alFromDate"
                      name="from_date"
                      class="form-control al-input"
                      value="2026-10-05"
                      required
                    >
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <div class="al-field">
                    <label for="alToDate">To date</label>
                    <input
                      type="date"
                      id="alToDate"
                      name="to_date"
                      class="form-control al-input"
                      value="2026-10-05"
                      required
                    >
                  </div>
                </div>
              </div>

              <div class="al-field al-reason-field">
                <label for="alReason">Reason</label>
                <textarea
                  id="alReason"
                  name="reason"
                  class="form-control al-input al-textarea"
                  maxlength="1000"
                  required
                >Personal commitment. Please approve one day of leave.</textarea>
              </div>

              <p class="al-duration">Duration: 1 working day</p>

              <button type="submit" class="al-submit-button">
                Submit request
              </button>
            </form>
          </article>
        </div>

        <!-- Leave history -->
        <div class="col-12 col-xl-4">
          <article class="al-history-card">
            <h2>My leave requests</h2>

            <div class="al-request-list">
              <div class="al-request">
                <h3>
                  <time datetime="2026-10-05">05 Oct 2026</time>
                </h3>
                <p>Personal leave <span>/</span> 1 day</p>
                <span class="al-status al-status-pending">Pending</span>
              </div>

              <div class="al-request">
                <h3>
                  <time datetime="2026-09-21">21 Sep 2026</time>
                </h3>
                <p>Personal leave <span>/</span> 1 day</p>
                <span class="al-status al-status-approved">Approved</span>
              </div>
            </div>
          </article>
        </div>
      </div>
    </div>

    <footer class="al-footer">
      <span>Bhavi Creations / Employee / UI/UX concept - sample data</span>
      <span>17 / Desktop</span>
    </footer>
  </div>
</section>


<?php include 'footer.php'; ?>