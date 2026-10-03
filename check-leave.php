<?php include 'header.php'; ?>

<section class="checkk-leve">
  <!-- Sidebar -->
  <aside
    class="cl-sidebar offcanvas-lg offcanvas-start"
    id="checkLeaveSidebar"
    tabindex="-1"
    aria-labelledby="checkLeaveSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 id="checkLeaveSidebarTitle" class="offcanvas-title">
        Employee menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#checkLeaveSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="cl-sidebar-body">
      <a href="employee-dashboard.php" class="cl-brand">
        <span class="cl-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="cl-profile">
        <div class="cl-avatar">A</div>
        <h2>Employee</h2>
        <p>Ananya Kumar</p>
      </div>

      <nav class="cl-navigation" aria-label="Employee navigation">
        <a href="employee-dashboard.php"><span></span>Dashboard</a>
        <a href="employee-my-work.php"><span></span>My work</a>
        <a href="employee-brand-assets.php"><span></span>Brand assets</a>
        <a href="employee-client-requirements.php">
          <span></span>Client requirements
        </a>
        <a href="employee-apply-leave.php"><span></span>Apply leave</a>
        <a
          href="employee-check-leave.php"
          class="active"
          aria-current="page"
        >
          <span></span>Check leave
        </a>
      </nav>

      <div class="cl-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <div class="cl-main">
    <header class="cl-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="cl-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#checkLeaveSidebar"
          aria-controls="checkLeaveSidebar"
          aria-label="Open menu"
        >☰</button>

        <div class="cl-breadcrumb">Employee / Check leave</div>
      </div>

      <div class="cl-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="cl-role">Employee</span>
      </div>
    </header>

    <div class="cl-content">
      <div class="cl-heading">
        <h1>My leave calendar</h1>
        <p>Check your leave dates, approval status and company holidays.</p>
      </div>

      <div class="row cl-panels">
        <!-- Calendar -->
        <div class="col-12 col-xl-8">
          <article class="cl-calendar-card">
            <div class="cl-calendar-heading">
              <h2>October 2026</h2>
              <div class="cl-controls">
                <button type="button" aria-label="Previous month">&lt;</button>
                <button type="button">Today</button>
                <button type="button" aria-label="Next month">&gt;</button>
              </div>
            </div>

            <div class="cl-legend">
              <span><i class="cl-dot cl-dot-approved"></i>Approved leave</span>
              <span><i class="cl-dot cl-dot-pending"></i>Pending request</span>
              <span><i class="cl-dot cl-dot-holiday"></i>Company holiday</span>
            </div>

            <div class="cl-calendar-grid" aria-label="October 2026">
              <div class="cl-weekday">Mon</div>
              <div class="cl-weekday">Tue</div>
              <div class="cl-weekday">Wed</div>
              <div class="cl-weekday">Thu</div>
              <div class="cl-weekday">Fri</div>
              <div class="cl-weekday">Sat</div>
              <div class="cl-weekday">Sun</div>

              <div class="cl-day" aria-hidden="true"></div>
              <div class="cl-day" aria-hidden="true"></div>
              <div class="cl-day" aria-hidden="true"></div>
              <div class="cl-day">1</div>
              <div class="cl-day">2</div>
              <div class="cl-day">3</div>
              <div class="cl-day">4</div>

              <!-- Use cl-approved for an approved leave date -->
              <div
                class="cl-day cl-pending"
                aria-label="05 October, personal leave request pending"
              >
                <time datetime="2026-10-05">5</time>
                <small>Leave</small>
                <small>Pending</small>
              </div>
              <div class="cl-day">6</div>
              <div class="cl-day">7</div>
              <div class="cl-day">8</div>
              <div class="cl-day">9</div>
              <div class="cl-day">10</div>
              <div class="cl-day">11</div>

              <div class="cl-day">12</div>
              <div class="cl-day">13</div>
              <div class="cl-day">14</div>
              <div class="cl-day">15</div>
              <div class="cl-day">16</div>
              <div class="cl-day">17</div>
              <div class="cl-day">18</div>

              <div class="cl-day">19</div>
              <div
                class="cl-day cl-holiday"
                aria-label="20 October, Vijayadashami company holiday"
              >
                <time datetime="2026-10-20">20</time>
                <small>Holiday</small>
              </div>
              <div class="cl-day">21</div>
              <div class="cl-day">22</div>
              <div class="cl-day">23</div>
              <div class="cl-day">24</div>
              <div class="cl-day">25</div>

              <div class="cl-day">26</div>
              <div class="cl-day">27</div>
              <div class="cl-day">28</div>
              <div class="cl-day">29</div>
              <div class="cl-day">30</div>
              <div class="cl-day">31</div>
              <div class="cl-day" aria-hidden="true"></div>
            </div>

            <p class="cl-calendar-note">
              Pending requests are awaiting Sir's approval.
            </p>
          </article>
        </div>

        <!-- Leave details -->
        <div class="col-12 col-xl-4">
          <article class="cl-details-card">
            <h2>My leave dates</h2>

            <div class="cl-detail-item">
              <span class="cl-date-badge">05 October 2026</span>
              <h3>Personal leave</h3>
              <p>1 day / Personal commitment</p>
              <span class="cl-status cl-status-pending">
                Pending approval
              </span>
            </div>

            <div class="cl-detail-item">
              <h2>Upcoming holiday</h2>
              <span class="cl-date-badge">20 October 2026</span>
              <h3>Vijayadashami</h3>
              <p>Company holiday</p>
              <span class="cl-status cl-status-holiday">Published by Admin</span>
            </div>

            <a href="employee-apply-leave.php" class="cl-apply-button">
              Apply leave
            </a>
          </article>
        </div>
      </div>
    </div>

    <footer class="cl-footer">
      <span>Bhavi Creations / Employee / Sample data</span>
      <span>18 / Desktop</span>
    </footer>
  </div>
</section>

<?php include 'footer.php'; ?>