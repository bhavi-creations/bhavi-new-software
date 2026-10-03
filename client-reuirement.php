<?php include 'header.php'; ?>

<section class="client-reuirement">
  <!-- Employee sidebar -->
  <aside
    class="cr-sidebar offcanvas-lg offcanvas-start"
    id="clientRequirementSidebar"
    tabindex="-1"
    aria-labelledby="clientRequirementSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="clientRequirementSidebarTitle">
        Employee menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#clientRequirementSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="cr-sidebar-body">
      <a href="employee-dashboard.php" class="cr-brand">
        <span class="cr-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="cr-profile">
        <div class="cr-avatar">A</div>
        <h2>Employee</h2>
        <p>Ananya Kumar</p>
      </div>

      <nav class="cr-navigation" aria-label="Employee navigation">
        <a href="employee-dashboard.php">
          <span></span>Dashboard
        </a>
        <a href="employee-my-work.php">
          <span></span>My work
        </a>
        <a href="employee-brand-assets.php">
          <span></span>Brand assets
        </a>
        <a
          href="employee-client-requirements.php"
          class="active"
          aria-current="page"
        >
          <span></span>Client requirements
        </a>
        <a href="employee-apply-leave.php">
          <span></span>Apply leave
        </a>
        <a href="employee-holidays.php">
          <span></span>Holidays
        </a>
      </nav>

      <div class="cr-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="cr-main">
    <header class="cr-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="cr-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#clientRequirementSidebar"
          aria-controls="clientRequirementSidebar"
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

        <div class="cr-breadcrumb">
          Employee <span>/</span> Client requirements
        </div>
      </div>

      <div class="cr-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="cr-role-badge">Employee</span>
      </div>
    </header>

    <div class="cr-content">
      <div class="cr-heading">
        <h1>Client requirements</h1>
        <p>
          Client briefs with reference files, video instructions and approved text.
        </p>
      </div>

      <!-- Client selection -->
      <div class="cr-client-row">
        <div class="cr-client-field">
          <label for="crClient">Client</label>
          <select
            id="crClient"
            name="client"
            class="form-select cr-input"
          >
            <option value="ialign">IALIGN Signature Dental</option>
            <option value="kdc">Krishna Denta Cure</option>
            <option value="vision">Vision Dental</option>
            <option value="vv">V&amp;V Salon</option>
          </select>
        </div>

        <span class="cr-assigned-badge">Assigned</span>
      </div>

      <!-- Brief and reference files -->
      <div class="row cr-panels">
        <div class="col-12 col-xl-8">
          <article class="cr-brief-card">
            <h2>Weekend smile campaign</h2>
            <span class="cr-due-badge">Due 06 Oct</span>

            <div class="cr-brief-details">
              <h3>Client brief</h3>
              <p>Create 2 Instagram posters and 1 reel.</p>
              <p>Use approved blue and white brand colours.</p>
              <p>Keep doctor photos and the logo unchanged.</p>
              <p>Poster size: 1080 x 1350. Reel: 1080 x 1920.</p>
            </div>

            <div class="cr-approved-text">
              <h3>Approved text</h3>
              <h4>The duo your smile needs</h4>
              <p>Book your consultation today.</p>
            </div>
          </article>
        </div>

        <div class="col-12 col-xl-4">
          <article class="cr-reference-card">
            <h2>Reference files</h2>

            <div class="cr-file-list">
              <div class="cr-file">
                <h3>Reference.jpg</h3>
                <p>JPEG / Design reference</p>
                <button
                  type="button"
                  class="cr-download"
                  aria-label="Download Reference.jpg"
                >
                  Download file
                </button>
              </div>

              <div class="cr-file">
                <h3>Doctor_video.mp4</h3>
                <p>MP4 / Reel footage</p>
                <button
                  type="button"
                  class="cr-download"
                  aria-label="Download Doctor_video.mp4"
                >
                  Download file
                </button>
              </div>

              <div class="cr-file">
                <h3>Approved_copy.txt</h3>
                <p>TXT / Headline &amp; caption</p>
                <button
                  type="button"
                  class="cr-download"
                  aria-label="Download Approved_copy.txt"
                >
                  Download file
                </button>
              </div>
            </div>
          </article>
        </div>
      </div>
    </div>

    <footer class="cr-footer">
      <span>Bhavi Creations / Employee / UI/UX concept - sample data</span>
      <span>16 / Desktop</span>
    </footer>
  </div>
</section>

<?php include 'footer.php'; ?>