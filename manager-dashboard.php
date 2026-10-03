<?php include 'header.php'; ?>


  <section class="manager-dashboard">

    <!-- Sidebar -->
    <aside
      class="md-sidebar offcanvas-lg offcanvas-start"
      id="managerSidebar"
      tabindex="-1"
      aria-labelledby="managerSidebarTitle"
    >
      <button
        type="button"
        class="btn-close btn-close-white d-lg-none md-sidebar-close"
        data-bs-dismiss="offcanvas"
        data-bs-target="#managerSidebar"
        aria-label="Close menu"
      ></button>

      <div class="md-sidebar-inner">

        <!-- Brand -->
        <a href="index.html" class="md-brand">
          <span class="md-brand-icon">B</span>

          <span>
            <strong class="md-brand-name">bhavi</strong>
            <small class="md-brand-caption">TEAM WORKSPACE</small>
          </span>
        </a>

        <!-- Manager profile -->
        <div class="md-profile">
          <div class="md-avatar" aria-hidden="true">S</div>
          <h2 id="managerSidebarTitle">Manager</h2>
          <p>Sir</p>
        </div>

        <!-- Navigation -->
        <nav class="md-navigation" aria-label="Manager navigation">
          <a href="manager-dashboard.php" class="active" aria-current="page">
            <span class="md-nav-dot"></span>
            Dashboard
          </a>

          <a href="manager-dashboard.php?view=team">
            <span class="md-nav-dot"></span>
            Team overview
          </a>

          <a href="manager-dashboard.php?view=work">
            <span class="md-nav-dot"></span>
            Daily work
          </a>

          <a href="manager-dashboard.php?view=leave">
            <span class="md-nav-dot"></span>
            Leave requests
          </a>

          <a href="manager-dashboard.php?view=holidays">
            <span class="md-nav-dot"></span>
            Holidays
          </a>

          <a href="manager-dashboard.php?view=notifications">
            <span class="md-nav-dot"></span>
            Notifications
          </a>
        </nav>

        <!-- Workspace -->
        <div class="md-workspace">
          <strong>Manager workspace</strong>
          <button type="button" class="md-signout">Sign out</button>
        </div>

      </div>
    </aside>

    <!-- Main area -->
    <div class="md-main">

      <!-- Top header -->
      <header class="md-topbar">
        <button
          class="md-menu-button d-lg-none"
          type="button"
          data-bs-toggle="offcanvas"
          data-bs-target="#managerSidebar"
          aria-controls="managerSidebar"
          aria-label="Open menu"
        >
          <span></span>
          <span></span>
          <span></span>
        </button>

        <div class="md-breadcrumb">
          Manager <span>/</span> Dashboard
        </div>

        <time class="md-date" datetime="2026-10-03">
          03 October 2026
        </time>

        <span class="md-role">Manager</span>
      </header>

      <main class="md-content">

        <!-- Introduction -->
        <div class="md-introduction">
          <h1>Good morning, Sir</h1>
          <p>Your team, daily submissions and pending requests at a glance.</p>
        </div>

        <!-- Summary cards -->
        <div class="row g-3">

          <div class="col-6 col-xl-3">
            <article class="md-summary-card">
              <h2>Total employees</h2>
              <strong class="md-summary-number">10</strong>
              <p>Updates when employees are added</p>
            </article>
          </div>

          <div class="col-6 col-xl-3">
            <article class="md-summary-card">
              <h2>Work submitted today</h2>
              <strong class="md-summary-number md-green">8</strong>
              <p>2 submissions pending</p>
            </article>
          </div>

          <div class="col-6 col-xl-3">
            <article class="md-summary-card">
              <h2>Leave requests</h2>
              <strong class="md-summary-number">2</strong>
              <p>Waiting for your approval</p>
            </article>
          </div>

          <div class="col-6 col-xl-3">
            <article class="md-summary-card">
              <h2>Upcoming holidays</h2>
              <strong class="md-summary-number">1</strong>
              <p>Vijayadashami - 20 Oct</p>
            </article>
          </div>

        </div>

        <!-- Department and attention panels -->
        <div class="md-panels">

          <section class="md-panel md-department-panel">
            <h2>Today by department</h2>
            <p class="md-panel-description">
              Submitted / active employees
            </p>

            <div class="md-department">
              <div class="md-department-heading">
                <strong>Design &amp; video</strong>
                <span>3 / 4</span>
              </div>

              <div
                class="progress"
                role="progressbar"
                aria-label="Design and video submissions"
                aria-valuenow="75"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div class="progress-bar" style="width: 75%;"></div>
              </div>
            </div>

            <div class="md-department">
              <div class="md-department-heading">
                <strong>Website</strong>
                <span>2 / 2</span>
              </div>

              <div
                class="progress"
                role="progressbar"
                aria-label="Website submissions"
                aria-valuenow="100"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div class="progress-bar" style="width: 100%;"></div>
              </div>
            </div>

            <div class="md-department">
              <div class="md-department-heading">
                <strong>SEO</strong>
                <span>2 / 3</span>
              </div>

              <div
                class="progress"
                role="progressbar"
                aria-label="SEO submissions"
                aria-valuenow="67"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div class="progress-bar" style="width: 67%;"></div>
              </div>
            </div>

            <div class="md-department">
              <div class="md-department-heading">
                <strong>Social media</strong>
                <span>1 / 1</span>
              </div>

              <div
                class="progress"
                role="progressbar"
                aria-label="Social media submissions"
                aria-valuenow="100"
                aria-valuemin="0"
                aria-valuemax="100"
              >
                <div class="progress-bar" style="width: 100%;"></div>
              </div>
            </div>
          </section>

          <section class="md-panel md-attention-panel">
            <h2>Needs your attention</h2>

            <article class="md-attention-item">
              <h3>Ananya applied for leave</h3>
              <p>05 Oct / Personal leave</p>
            </article>

            <article class="md-attention-item">
              <h3>Rahul submitted daily work</h3>
              <p>SEO / 4 completed tasks</p>
            </article>

            <article class="md-attention-item">
              <h3>2 employees pending</h3>
              <p>Design &amp; SEO departments</p>
            </article>
          </section>

        </div>

        <!-- Action buttons -->
        <div class="md-actions">
          <a
            href="manager-dashboard.php?view=work"
            class="btn md-primary-button"
          >
            View daily report
          </a>

          <a
            href="manager-dashboard.php?view=team"
            class="btn md-secondary-button"
          >
            Team overview
          </a>
        </div>

        <!-- Footer -->
        <footer class="md-footer">
          <span>
            Bhavi Creations / Manager / UI/UX concept - sample data
          </span>
          <span>01 / Desktop</span>
        </footer>

      </main>
    </div>

  </section>


  <?php include 'footer.php'; ?>