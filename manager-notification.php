<?php include 'header.php'; ?>

<section class="manager-notification">

  <!-- Sidebar -->
  <aside
    class="mn-sidebar offcanvas-lg offcanvas-start"
    id="managerNotificationSidebar"
    tabindex="-1"
    aria-labelledby="managerNotificationSidebarTitle"
  >
    <button
      type="button"
      class="btn-close btn-close-white mn-close d-lg-none"
      data-bs-dismiss="offcanvas"
      data-bs-target="#managerNotificationSidebar"
      aria-label="Close navigation"
    ></button>

    <div class="mn-sidebar-inner">

      <a href="manager-dashboard.php" class="mn-brand">
        <span class="mn-brand-icon">B</span>

        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="mn-profile">
        <div class="mn-avatar" aria-hidden="true">S</div>
        <h2 id="managerNotificationSidebarTitle">Manager</h2>
        <p>Sir</p>
      </div>

      <nav class="mn-navigation" aria-label="Manager navigation">
        <a href="manager-dashboard.php">
          <span class="mn-dot"></span>
          Dashboard
        </a>

        <a href="manager-dashboard.php?view=team">
          <span class="mn-dot"></span>
          Team overview
        </a>

        <a href="manager-dashboard.php?view=work">
          <span class="mn-dot"></span>
          Daily work
        </a>

        <a href="manager-dashboard.php?view=leave">
          <span class="mn-dot"></span>
          Leave requests
        </a>

        <a href="manager-dashboard.php?view=holidays">
          <span class="mn-dot"></span>
          Holidays
        </a>

        <a
          href="manager-dashboard.php?view=notifications"
          class="active"
          aria-current="page"
        >
          <span class="mn-dot"></span>
          Notifications
        </a>
      </nav>

      <div class="mn-workspace">
        <strong>Manager workspace</strong>
        <button type="button" class="mn-signout">Sign out</button>
      </div>

    </div>
  </aside>

  <!-- Main area -->
  <div class="mn-main">

    <header class="mn-header">

      <button
        type="button"
        class="mn-menu-button d-lg-none"
        data-bs-toggle="offcanvas"
        data-bs-target="#managerNotificationSidebar"
        aria-controls="managerNotificationSidebar"
        aria-label="Open navigation"
      >
        <span></span>
        <span></span>
        <span></span>
      </button>

      <div class="mn-breadcrumb">
        Manager <span>/</span> Notifications
      </div>

      <time class="mn-header-date" datetime="2026-10-03">
        03 October 2026
      </time>

      <span class="mn-role">Manager</span>

    </header>

    <main class="mn-content">

      <div class="mn-heading">
        <h1>Notifications</h1>
        <p>Daily work submissions and leave requests from your team.</p>
      </div>

      <!-- Notifications -->
      <div class="mn-notification-list">

        <article class="mn-notification-card">
          <div class="mn-notification-icon" aria-hidden="true">W</div>

          <div class="mn-notification-info">
            <h2>Ananya submitted daily work</h2>
            <p>
              Design &amp; video
              <span>/</span>
              2 posters, 1 carousel
              <span>/</span>
              03 Oct, 06:20 PM
            </p>
          </div>

          <a
            href="manager-dashboard.php?view=work&amp;date=2026-10-03"
            class="btn mn-view-button"
            aria-label="View Ananya's daily work report"
          >
            View
          </a>
        </article>

        <article class="mn-notification-card">
          <div class="mn-notification-icon" aria-hidden="true">W</div>

          <div class="mn-notification-info">
            <h2>Rahul submitted daily work</h2>
            <p>
              SEO
              <span>/</span>
              4 completed tasks
              <span>/</span>
              03 Oct, 05:55 PM
            </p>
          </div>

          <a
            href="manager-dashboard.php?view=work&amp;date=2026-10-03"
            class="btn mn-view-button"
            aria-label="View Rahul's daily work report"
          >
            View
          </a>
        </article>

        <article class="mn-notification-card">
          <div class="mn-notification-icon" aria-hidden="true">L</div>

          <div class="mn-notification-info">
            <h2>Ananya requested leave</h2>
            <p>
              05 Oct 2026
              <span>/</span>
              Personal leave
              <span>/</span>
              Awaiting approval
            </p>
          </div>

          <a
            href="manager-dashboard.php?view=leave"
            class="btn mn-view-button"
            aria-label="View Ananya's leave request"
          >
            View
          </a>
        </article>

      </div>

      <!-- Example submission success message -->
      <div class="mn-success-section">
        <h2>Submission success state</h2>

        <div class="mn-success-message" role="status">
          Daily work submitted successfully. Sir has been notified in the portal.
        </div>
      </div>

      <footer class="mn-footer">
        <span>
          Bhavi Creations / Manager / UI/UX concept - sample data
        </span>

        <span>03 / Desktop</span>
      </footer>

    </main>

  </div>

</section>

<?php include 'footer.php'; ?>