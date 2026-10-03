<?php include 'header.php'; ?>


<section class="employee-brands-assets">
  <!-- Employee sidebar -->
  <aside
    class="eba-sidebar offcanvas-lg offcanvas-start"
    id="employeeBrandAssetsSidebar"
    tabindex="-1"
    aria-labelledby="employeeBrandAssetsSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="employeeBrandAssetsSidebarTitle">
        Employee menu
      </h5>
      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#employeeBrandAssetsSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="eba-sidebar-body">
      <a href="employee-dashboard.php" class="eba-brand">
        <span class="eba-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="eba-profile">
        <div class="eba-avatar">A</div>
        <h2>Employee</h2>
        <p>Ananya Kumar</p>
      </div>

      <nav class="eba-navigation" aria-label="Employee navigation">
        <a href="employee-dashboard.php">
          <span></span>Dashboard
        </a>
        <a href="employee-my-work.php">
          <span></span>My work
        </a>
        <a
          href="employee-brand-assets.php"
          class="active"
          aria-current="page"
        >
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

      <div class="eba-workspace">
        <strong>Employee workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="eba-main">
    <header class="eba-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="eba-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#employeeBrandAssetsSidebar"
          aria-controls="employeeBrandAssetsSidebar"
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

        <div class="eba-breadcrumb">
          Employee <span>/</span> Brand assets
        </div>
      </div>

      <div class="eba-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="eba-role-badge">Employee</span>
      </div>
    </header>

    <div class="eba-content">
      <div class="eba-heading">
        <h1>Brand assets</h1>
        <p>
          Find client logos, intro videos, outro videos and editable source files.
        </p>
      </div>

      <!-- Filters -->
      <div class="row eba-filter-row">
        <div class="col-12 col-md-6">
          <label for="ebaClient" class="eba-label">Client</label>
          <select
            id="ebaClient"
            name="client"
            class="form-select eba-input eba-select"
          >
            <option value="kdc">Krishna Denta Cure</option>
            <option value="vision">Vision Dental</option>
            <option value="vv">V&amp;V Salon</option>
            <option value="bhavi">Bhavi Creations</option>
          </select>
        </div>

        <div class="col-12 col-md-6">
          <label for="ebaSearch" class="eba-label">Search assets</label>
          <input
            type="search"
            id="ebaSearch"
            name="search"
            class="form-control eba-input"
            placeholder="Search file name"
          >
        </div>
      </div>

      <!-- Asset cards -->
      <div class="row eba-assets-row">
        <div class="col-12 col-md-6 col-xl-4">
          <article class="eba-asset-card">
            <span class="eba-file-icon">PNG</span>
            <h2>Logo</h2>
            <p class="eba-file-name">KDC_logo.png</p>

            <div class="eba-card-bottom">
              <span class="eba-file-meta">PNG / 2.4 MB</span>
              <button
                type="button"
                class="eba-download-button"
                aria-label="Download KDC logo"
              >
                Download
              </button>
            </div>
          </article>
        </div>

        <div class="col-12 col-md-6 col-xl-4">
          <article class="eba-asset-card">
            <span class="eba-file-icon">MP4</span>
            <h2>Intro</h2>
            <p class="eba-file-name">KDC_intro.mp4</p>

            <div class="eba-card-bottom">
              <span class="eba-file-meta">MP4 / 14 MB</span>
              <button
                type="button"
                class="eba-download-button"
                aria-label="Download KDC intro video"
              >
                Download
              </button>
            </div>
          </article>
        </div>

        <div class="col-12 col-md-6 col-xl-4">
          <article class="eba-asset-card">
            <span class="eba-file-icon">MP4</span>
            <h2>Outro</h2>
            <p class="eba-file-name">KDC_outro.mp4</p>

            <div class="eba-card-bottom">
              <span class="eba-file-meta">MP4 / 10 MB</span>
              <button
                type="button"
                class="eba-download-button"
                aria-label="Download KDC outro video"
              >
                Download
              </button>
            </div>
          </article>
        </div>

        <div class="col-12 col-md-6 col-xl-4">
          <article class="eba-asset-card">
            <span class="eba-file-icon">PSD</span>
            <h2>PSD source</h2>
            <p class="eba-file-name">KDC_poster.psd</p>

            <div class="eba-card-bottom">
              <span class="eba-file-meta">PSD / 48 MB</span>
              <button
                type="button"
                class="eba-download-button"
                aria-label="Download KDC poster PSD"
              >
                Download
              </button>
            </div>
          </article>
        </div>
      </div>
    </div>

    <footer class="eba-footer">
      <span>Bhavi Creations / Employee / UI/UX concept - sample data</span>
      <span>15 / Desktop</span>
    </footer>
  </div>
</section>

<?php include 'footer.php'; ?>