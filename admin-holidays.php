<?php include 'header.php'; ?>

<section class="admin-holidays">
  <!-- Sidebar -->
  <aside
    class="ah-sidebar offcanvas-lg offcanvas-start"
    id="adminHolidaysSidebar"
    tabindex="-1"
    aria-labelledby="adminHolidaysSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="adminHolidaysSidebarTitle">
        Admin menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#adminHolidaysSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="ah-sidebar-body">
      <a href="admin-dashboard.php" class="ah-brand">
        <span class="ah-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="ah-profile">
        <div class="ah-avatar">A</div>
        <h2>Admin</h2>
        <p>Account administrator</p>
      </div>

      <nav class="ah-navigation" aria-label="Admin navigation">
        <a href="admin-dashboard.php">
          <span></span>Dashboard
        </a>
        <a href="admin-employees.php">
          <span></span>Employees
        </a>
        <a href="admin-add-employee.php">
          <span></span>Add employee
        </a>
        <a href="admin-holidays.php" class="active" aria-current="page">
          <span></span>Holidays
        </a>
      </nav>

      <div class="ah-workspace">
        <strong>Admin workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main workspace -->
  <div class="ah-main">
    <header class="ah-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="ah-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#adminHolidaysSidebar"
          aria-controls="adminHolidaysSidebar"
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

        <div class="ah-breadcrumb">
          Admin <span>/</span> Holidays
        </div>
      </div>

      <div class="ah-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="ah-admin-badge">Admin</span>
      </div>
    </header>

    <div class="ah-content">
      <div class="ah-page-heading">
        <div>
          <h1>Holiday calendar</h1>
          <p>Company holidays visible to every employee.</p>
        </div>

        <a href="#ahHolidayName" class="ah-add-button">
          Add holiday
        </a>
      </div>

      <div class="row ah-panels">
        <!-- Calendar -->
        <div class="col-12 col-xl-8">
          <article class="ah-calendar-card">
            <div class="ah-calendar-heading">
              <h2>October 2026</h2>

              <div class="ah-calendar-controls">
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
              class="ah-calendar-grid"
              aria-label="October 2026 holiday calendar"
            >
              <div class="ah-weekday">Mon</div>
              <div class="ah-weekday">Tue</div>
              <div class="ah-weekday">Wed</div>
              <div class="ah-weekday">Thu</div>
              <div class="ah-weekday">Fri</div>
              <div class="ah-weekday">Sat</div>
              <div class="ah-weekday">Sun</div>

              <div class="ah-day" aria-hidden="true"></div>
              <div class="ah-day" aria-hidden="true"></div>
              <div class="ah-day" aria-hidden="true"></div>
              <div class="ah-day"><time datetime="2026-10-01">1</time></div>
              <div class="ah-day"><time datetime="2026-10-02">2</time></div>
              <div class="ah-day"><time datetime="2026-10-03">3</time></div>
              <div class="ah-day"><time datetime="2026-10-04">4</time></div>

              <div class="ah-day"><time datetime="2026-10-05">5</time></div>
              <div class="ah-day"><time datetime="2026-10-06">6</time></div>
              <div class="ah-day"><time datetime="2026-10-07">7</time></div>
              <div class="ah-day"><time datetime="2026-10-08">8</time></div>
              <div class="ah-day"><time datetime="2026-10-09">9</time></div>
              <div class="ah-day"><time datetime="2026-10-10">10</time></div>
              <div class="ah-day"><time datetime="2026-10-11">11</time></div>

              <div class="ah-day"><time datetime="2026-10-12">12</time></div>
              <div class="ah-day"><time datetime="2026-10-13">13</time></div>
              <div class="ah-day"><time datetime="2026-10-14">14</time></div>
              <div class="ah-day"><time datetime="2026-10-15">15</time></div>
              <div class="ah-day"><time datetime="2026-10-16">16</time></div>
              <div class="ah-day"><time datetime="2026-10-17">17</time></div>
              <div class="ah-day"><time datetime="2026-10-18">18</time></div>

              <div class="ah-day"><time datetime="2026-10-19">19</time></div>
              <div
                class="ah-day ah-holiday"
                aria-label="20 October 2026, Vijayadashami, company holiday"
              >
                <time datetime="2026-10-20">20</time>
                <small>Holiday</small>
              </div>
              <div class="ah-day"><time datetime="2026-10-21">21</time></div>
              <div class="ah-day"><time datetime="2026-10-22">22</time></div>
              <div class="ah-day"><time datetime="2026-10-23">23</time></div>
              <div class="ah-day"><time datetime="2026-10-24">24</time></div>
              <div class="ah-day"><time datetime="2026-10-25">25</time></div>

              <div class="ah-day"><time datetime="2026-10-26">26</time></div>
              <div class="ah-day"><time datetime="2026-10-27">27</time></div>
              <div class="ah-day"><time datetime="2026-10-28">28</time></div>
              <div class="ah-day"><time datetime="2026-10-29">29</time></div>
              <div class="ah-day"><time datetime="2026-10-30">30</time></div>
              <div class="ah-day"><time datetime="2026-10-31">31</time></div>
              <div class="ah-day" aria-hidden="true"></div>
            </div>
          </article>
        </div>

        <!-- Holiday details and form -->
        <div class="col-12 col-xl-4">
          <article class="ah-holiday-card">
            <h2>Upcoming holiday</h2>
            <span class="ah-date-badge">20 October</span>

            <h3>Vijayadashami</h3>
            <p class="ah-holiday-type">Company holiday</p>

            <form
              class="ah-holiday-form"
              action="admin-save-holiday.php"
              method="post"
            >
              <!-- Add your backend CSRF token here -->

              <div class="ah-field">
                <label for="ahHolidayName">Holiday name</label>
                <input
                  type="text"
                  id="ahHolidayName"
                  name="holiday_name"
                  class="form-control ah-input"
                  value="Vijayadashami"
                  maxlength="150"
                  required
                >
              </div>

              <div class="ah-field">
                <label for="ahHolidayDate">Date</label>
                <input
                  type="date"
                  id="ahHolidayDate"
                  name="holiday_date"
                  class="form-control ah-input"
                  value="2026-10-20"
                  required
                >
              </div>

              <button type="submit" class="ah-save-button">
                Save holiday
              </button>

              <p class="ah-form-note">
                Visible in all employee dashboards.
              </p>
            </form>
          </article>
        </div>
      </div>
    </div>

    <footer class="ah-footer">
      <span>Bhavi Creations / Admin / UI/UX concept - sample data</span>
      <span>09 / Desktop</span>
    </footer>
  </div>
</section>


<?php include 'footer.php'; ?>