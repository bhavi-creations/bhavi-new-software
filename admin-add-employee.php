<?php include 'header.php'; ?>


<section class="admin-add-employees">
  <!-- Sidebar -->
  <aside
    class="aae-sidebar offcanvas-lg offcanvas-start"
    id="adminAddEmployeeSidebar"
    tabindex="-1"
    aria-labelledby="adminAddEmployeeSidebarTitle"
  >
    <div class="offcanvas-header d-lg-none">
      <h5 class="offcanvas-title" id="adminAddEmployeeSidebarTitle">
        Admin menu
      </h5>

      <button
        type="button"
        class="btn-close btn-close-white"
        data-bs-dismiss="offcanvas"
        data-bs-target="#adminAddEmployeeSidebar"
        aria-label="Close menu"
      ></button>
    </div>

    <div class="aae-sidebar-body">
      <a href="admin-dashboard.php" class="aae-brand">
        <span class="aae-brand-icon">B</span>
        <span>
          <strong>bhavi</strong>
          <small>TEAM WORKSPACE</small>
        </span>
      </a>

      <div class="aae-profile">
        <div class="aae-avatar">A</div>
        <h2>Admin</h2>
        <p>Account administrator</p>
      </div>

      <nav class="aae-navigation" aria-label="Admin navigation">
        <a href="admin-dashboard.php">
          <span></span>Dashboard
        </a>

        <a href="admin-employees.php">
          <span></span>Employees
        </a>

        <a
          href="admin-add-employee.php"
          class="active"
          aria-current="page"
        >
          <span></span>Add employee
        </a>

        <a href="admin-holidays.php">
          <span></span>Holidays
        </a>
      </nav>

      <div class="aae-workspace">
        <strong>Admin workspace</strong>
        <button type="button">Sign out</button>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <div class="aae-main">
    <header class="aae-header">
      <div class="d-flex align-items-center gap-3">
        <button
          type="button"
          class="aae-menu-toggle d-lg-none"
          data-bs-toggle="offcanvas"
          data-bs-target="#adminAddEmployeeSidebar"
          aria-controls="adminAddEmployeeSidebar"
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

        <div class="aae-breadcrumb">
          Admin <span>/</span> Add employee
        </div>
      </div>

      <div class="aae-header-right">
        <time datetime="2026-10-03">03 October 2026</time>
        <span class="aae-admin-badge">Admin</span>
      </div>
    </header>

    <div class="aae-content">
      <div class="aae-heading">
        <h1>Add employee</h1>
        <p>Create a unique account and assign the correct work dashboard.</p>
      </div>

      <!-- Employee form -->
      <div class="aae-form-card">
        <form method="post" action="admin-create-employee.php">
          <!-- Add your backend CSRF token here -->

          <div class="row aae-fields">
            <div class="col-12 col-md-6">
              <label for="aaeName" class="aae-label">
                Employee name
              </label>
              <input
                type="text"
                id="aaeName"
                name="employee_name"
                class="form-control aae-input"
                placeholder="Ananya Kumar"
                autocomplete="name"
                maxlength="100"
                required
              >
            </div>

            <div class="col-12 col-md-6">
              <label for="aaeEmail" class="aae-label">
                Email address
              </label>
              <input
                type="email"
                id="aaeEmail"
                name="email"
                class="form-control aae-input"
                placeholder="ananya@example.com"
                autocomplete="email"
                maxlength="254"
                required
              >
            </div>

            <div class="col-12 col-md-6">
              <label for="aaeDepartment" class="aae-label">
                Department
              </label>
              <select
                id="aaeDepartment"
                name="department"
                class="form-select aae-input aae-select"
                required
              >
                <option value="design">Design &amp; video</option>
                <option value="website">Website</option>
                <option value="seo">SEO</option>
                <option value="social">Social media</option>
                <option value="telecaller">Telecaller</option>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label for="aaeDesignation" class="aae-label">
                Designation
              </label>
              <input
                type="text"
                id="aaeDesignation"
                name="designation"
                class="form-control aae-input"
                placeholder="Graphic designer"
                maxlength="100"
                required
              >
            </div>

            <div class="col-12 col-md-6">
              <label for="aaeUsername" class="aae-label">
                Username
              </label>
              <input
                type="text"
                id="aaeUsername"
                name="username"
                class="form-control aae-input"
                placeholder="ananya.design"
                autocomplete="off"
                autocapitalize="none"
                spellcheck="false"
                maxlength="50"
                aria-describedby="aaeUsernameError"
                required
              >
            </div>

            <div class="col-12 col-md-6">
              <label for="aaePassword" class="aae-label">
                Temporary password
              </label>
              <input
                type="password"
                id="aaePassword"
                name="temporary_password"
                class="form-control aae-input"
                placeholder="Enter temporary password"
                autocomplete="new-password"
                required
              >
            </div>

            <div class="col-12 col-md-6">
              <label for="aaeJoiningDate" class="aae-label">
                Joining date
              </label>
              <input
                type="date"
                id="aaeJoiningDate"
                name="joining_date"
                class="form-control aae-input"
                value="2026-10-03"
                required
              >
            </div>

            <div class="col-12 col-md-6">
              <label for="aaeStatus" class="aae-label">
                Account status
              </label>
              <select
                id="aaeStatus"
                name="account_status"
                class="form-select aae-input aae-select"
                required
              >
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

          <div class="aae-access-row">
            <p>
              Employee access: own work, brand assets, client briefs,
              leave and holidays.
            </p>
            <span class="aae-role-badge">Role: Employee</span>
          </div>

          <!-- Reference image error state.
               Show only when the backend detects a duplicate username. -->
          <p id="aaeUsernameError" class="aae-error">
            Username already exists. Choose a different username.
          </p>

          <div class="aae-form-actions">
            <button type="submit" class="aae-create-button">
              Create employee
            </button>

            <a href="admin-employees.php" class="aae-cancel-button">
              Cancel
            </a>
          </div>
        </form>
      </div>
    </div>

    <footer class="aae-footer">
      <span>Bhavi Creations / Admin / UI/UX concept - sample data</span>
      <span>08 / Desktop</span>
    </footer>
  </div>
</section>

<script>
  (() => {
  const section = document.querySelector(".admin-clilient-add");
  if (!section) return;

  const input = section.querySelector("#acaLogoFile");
  const preview = section.querySelector("#acaLogoPreview");
  const icon = section.querySelector("#acaPreviewIcon");
  const message = section.querySelector("#acaLogoMessage");

  let previewUrl = null;

  input.addEventListener("change", () => {
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }

    preview.hidden = true;
    preview.removeAttribute("src");
    icon.hidden = false;
    input.setCustomValidity("");
    message.style.color = "";

    const file = input.files[0];

    if (!file) {
      message.textContent = "Your uploaded logo will appear here.";
      return;
    }

    const extension = file.name.split(".").pop().toLowerCase();
    const validExtension = ["png", "jpg", "jpeg", "svg"].includes(extension);

    if (!validExtension || file.size > 5 * 1024 * 1024) {
      const error = "Choose a PNG, JPG or SVG file under 5 MB.";

      input.value = "";
      input.setCustomValidity(error);
      message.textContent = error;
      message.style.color = "#dc3545";
      input.reportValidity();
      return;
    }

    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.hidden = false;
    icon.hidden = true;
    message.textContent = file.name;
  });

  preview.addEventListener("error", () => {
    preview.hidden = true;
    icon.hidden = false;
    message.textContent = "Unable to preview this image. Choose another logo.";
    input.setCustomValidity("Choose a valid logo image.");
  });
})();
</script>

<?php include 'footer.php'; ?>