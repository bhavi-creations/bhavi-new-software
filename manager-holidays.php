<?php include 'header.php'; ?>

<section class="manager-holidays">
  <!-- Sidebar -->
  <aside
    class="mh-sidebar offcanvas-lg offcanvas-start"
    tabindex="-1"
    id="managerHolidaySidebar"
    aria-labelledby="managerHolidaySidebarLabel"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="managerHolidaySidebarLabel">
        Manager menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#managerHolidaySidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="mh-sidebar-inner">
      <a href="manager-dashboard.php" class="mh-brand">
        <span class="mh-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="mh-profile">
        <div class="mh-avatar">S</div>
        <h2>Manager</h2>
        <p>Sir</p>
      </div>

      <nav class="mh-navigation" aria-label="Manager navigation">
        <a href="manager-dashboard.php">
          <span class="mh-nav-dot"></span> Dashboard
        </a>
        <a href="manager-dashboard.php?view=team">
          <span class="mh-nav-dot"></span> Team overview
        </a>
        <a href="manager-dashboard.php?view=work">
          <span class="mh-nav-dot"></span> Daily work
        </a>
        <a href="manager-dashboard.php?view=leave">
          <span class="mh-nav-dot"></span> Leave requests
        </a>
        <a
          href="manager-dashboard.php?view=holidays"
          class="active"
          aria-current="page"
        >
          <span class="mh-nav-dot"></span> Holidays
        </a>
        <a href="manager-dashboard.php?view=notifications">
          <span class="mh-nav-dot"></span> Notifications
        </a>
      </nav>

      <div class="mh-workspace">
        <strong>Manager workspace</strong>
        <button type="button" class="mh-signout">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="mh-main">
    <header class="mh-topbar">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="mh-menu-button d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#managerHolidaySidebar"
          aria-controls="managerHolidaySidebar"
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

        <div class="mh-breadcrumb">
          Manager <span>/</span> Holidays
        </div>
      </div>

      <div class="d-flex align-items-center mh-header-details">
        <time datetime="2026-10-03" class="mh-current-date">
          03 October 2026
        </time>
        <span class="mh-role-badge">Manager</span>
      </div>
    </header>

    <div class="mh-content">
      <div class="mh-page-heading">
        <h1>Holiday calendar</h1>
        <p>Company holidays visible to every employee.</p>
      </div>

      <div class="row g-4 align-items-stretch">
        <!-- Calendar -->
        <div class="col-12 col-xl-8">
          <div class="mh-calendar-card">
            <div class="mh-calendar-heading">
              <h2>October 2026</h2>

              <div class="mh-calendar-controls">
                <button type="button" aria-label="Previous month">
                  &lt;
                </button>
                <button type="button">Today</button>
                <button type="button" aria-label="Next month">
                  &gt;
                </button>
              </div>
            </div>

            <div
              class="mh-calendar-grid"
              aria-label="October 2026 holiday calendar"
            >
              <div class="mh-weekday">Mon</div>
              <div class="mh-weekday">Tue</div>
              <div class="mh-weekday">Wed</div>
              <div class="mh-weekday">Thu</div>
              <div class="mh-weekday">Fri</div>
              <div class="mh-weekday">Sat</div>
              <div class="mh-weekday">Sun</div>

              <div class="mh-day mh-empty" aria-hidden="true"></div>
              <div class="mh-day mh-empty" aria-hidden="true"></div>
              <div class="mh-day mh-empty" aria-hidden="true"></div>
              <div class="mh-day"><time datetime="2026-10-01">1</time></div>
              <div class="mh-day"><time datetime="2026-10-02">2</time></div>
              <div class="mh-day"><time datetime="2026-10-03">3</time></div>
              <div class="mh-day"><time datetime="2026-10-04">4</time></div>

              <div class="mh-day"><time datetime="2026-10-05">5</time></div>
              <div class="mh-day"><time datetime="2026-10-06">6</time></div>
              <div class="mh-day"><time datetime="2026-10-07">7</time></div>
              <div class="mh-day"><time datetime="2026-10-08">8</time></div>
              <div class="mh-day"><time datetime="2026-10-09">9</time></div>
              <div class="mh-day"><time datetime="2026-10-10">10</time></div>
              <div class="mh-day"><time datetime="2026-10-11">11</time></div>

              <div class="mh-day"><time datetime="2026-10-12">12</time></div>
              <div class="mh-day"><time datetime="2026-10-13">13</time></div>
              <div class="mh-day"><time datetime="2026-10-14">14</time></div>
              <div class="mh-day"><time datetime="2026-10-15">15</time></div>
              <div class="mh-day"><time datetime="2026-10-16">16</time></div>
              <div class="mh-day"><time datetime="2026-10-17">17</time></div>
              <div class="mh-day"><time datetime="2026-10-18">18</time></div>

              <div class="mh-day"><time datetime="2026-10-19">19</time></div>
              <div
                class="mh-day mh-holiday"
                aria-label="20 October 2026, Vijayadashami, company holiday"
              >
                <time datetime="2026-10-20">20</time>
                <small>Holiday</small>
              </div>
              <div class="mh-day"><time datetime="2026-10-21">21</time></div>
              <div class="mh-day"><time datetime="2026-10-22">22</time></div>
              <div class="mh-day"><time datetime="2026-10-23">23</time></div>
              <div class="mh-day"><time datetime="2026-10-24">24</time></div>
              <div class="mh-day"><time datetime="2026-10-25">25</time></div>

              <div class="mh-day"><time datetime="2026-10-26">26</time></div>
              <div class="mh-day"><time datetime="2026-10-27">27</time></div>
              <div class="mh-day"><time datetime="2026-10-28">28</time></div>
              <div class="mh-day"><time datetime="2026-10-29">29</time></div>
              <div class="mh-day"><time datetime="2026-10-30">30</time></div>
              <div class="mh-day"><time datetime="2026-10-31">31</time></div>
              <div class="mh-day mh-empty" aria-hidden="true"></div>
            </div>
          </div>
        </div>

        <!-- Upcoming holiday -->
        <div class="col-12 col-xl-4">
          <article class="mh-holiday-card">
            <h2>Upcoming holiday</h2>

            <span class="mh-holiday-date">20 October</span>

            <h3>Vijayadashami</h3>
            <p class="mh-holiday-type">Company holiday</p>

            <div class="mh-published">
              <h4>Published by Admin</h4>
              <p>All employees can view this holiday.</p>
            </div>
          </article>
        </div>
      </div>
    </div>

    <footer class="mh-footer">
      <span>Bhavi Creations / Manager / UI/UX concept - sample data</span>
      <span>05 / Desktop</span>
    </footer>
  </div>
</section>



<?php include 'footer.php'; ?>