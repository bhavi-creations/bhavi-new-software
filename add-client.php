<?php include 'header.php'; ?>

<section class="admin-clilient">
  <div class="acl-layout">

    <!-- Sidebar -->
    <aside
      class="acl-sidebar offcanvas-lg offcanvas-start"
      tabindex="-1"
      id="aclSidebar"
      aria-labelledby="aclSidebarTitle"
    >
      <div class="offcanvas-header d-lg-none">
        <h5 id="aclSidebarTitle" class="text-white mb-0">
          Admin menu
        </h5>

        <button
          type="button"
          class="btn-close btn-close-white"
          data-bs-dismiss="offcanvas"
          data-bs-target="#aclSidebar"
          aria-label="Close menu"
        ></button>
      </div>

      <div class="acl-sidebar-inner">
        <a href="admin-dashboard.php" class="acl-brand">
          <span class="acl-brand-icon">B</span>
          <span>
            <strong>bhavi</strong>
            <small>TEAM WORKSPACE</small>
          </span>
        </a>

        <div class="acl-profile">
          <div class="acl-avatar">A</div>
          <h3>Admin</h3>
          <p>Account administrator</p>
        </div>

        <nav class="acl-nav" aria-label="Admin navigation">
          <a href="admin-dashboard.php">
            <span class="acl-dot"></span>Dashboard
          </a>

          <a href="admin-employees.php">
            <span class="acl-dot"></span>Employees
          </a>

          <a href="admin-add-employees.php">
            <span class="acl-dot"></span>Add employee
          </a>

          <a
            href="admin-clients.php"
            class="active"
            aria-current="page"
          >
            <span class="acl-dot"></span>Clients
          </a>

          <a href="admin-client-add.php">
            <span class="acl-dot"></span>Add client
          </a>

          <a href="admin-holidays.php">
            <span class="acl-dot"></span>Holidays
          </a>
        </nav>

        <div class="acl-workspace">
          <strong>Admin workspace</strong>

          <form action="logout.php" method="post">
            <!-- Insert your server-generated CSRF token here. -->
            <button type="submit">Sign out</button>
          </form>
        </div>
      </div>
    </aside>

    <!-- Main content -->
    <div class="acl-main">
      <header class="acl-topbar">
        <div class="d-flex align-items-center gap-3">
          <button
            type="button"
            class="acl-menu-toggle d-lg-none"
            data-bs-toggle="offcanvas"
            data-bs-target="#aclSidebar"
            aria-controls="aclSidebar"
            aria-label="Open admin menu"
          >
            <span></span>
            <span></span>
            <span></span>
          </button>

          <div class="acl-breadcrumb">
            <span>Admin</span>
            <span>/</span>
            <span>Clients</span>
          </div>
        </div>

        <div class="acl-topbar-right">
          <time datetime="2026-10-03" class="acl-date">
            03 October 2026
          </time>
          <span class="acl-role">Admin</span>
        </div>
      </header>

      <div class="acl-content">
        <div class="acl-heading">
          <div>
            <h1>Clients</h1>
            <p>
              Manage client details, website links and brand logos.
            </p>
          </div>

          <a href="admin-client-add.php" class="btn acl-add">
            Add client
          </a>
        </div>

        <div class="acl-search-row">
          <div class="acl-search-field">
            <label for="aclClientSearch" class="form-label">
              Search clients
            </label>

            <input
              type="search"
              class="form-control"
              id="aclClientSearch"
              placeholder="Search client name or website"
              aria-controls="aclClientTable"
            >
          </div>

          <span class="acl-count" id="aclClientCount" aria-live="polite">
            5 clients
          </span>
        </div>

        <div
          class="acl-table-wrapper table-responsive"
          tabindex="0"
          role="region"
          aria-label="Clients table"
        >
          <table class="table acl-table" id="aclClientTable">
            <thead>
              <tr>
                <th scope="col">Logo</th>
                <th scope="col">Client name</th>
                <th scope="col">Website URL</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>

            <tbody>
              <tr data-client-row>
                <td>
                  <span class="acl-logo acl-logo-purple">KD</span>
                </td>
                <td>Krishna Denta Cure</td>
                <td>
                  <a
                    href="https://client-one.example"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="acl-website"
                  >
                    https://client-one.example
                  </a>
                </td>
                <td>
                  <div class="acl-actions">
                    <a href="admin-client-edit.php?id=1">Edit</a>
                    <span aria-hidden="true">/</span>
                    <a
                      href="https://client-one.example"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View website</a>
                  </div>
                </td>
              </tr>

              <tr data-client-row>
                <td>
                  <span class="acl-logo acl-logo-teal">IA</span>
                </td>
                <td>IALIGN Signature Dental</td>
                <td>
                  <a
                    href="https://client-two.example"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="acl-website"
                  >
                    https://client-two.example
                  </a>
                </td>
                <td>
                  <div class="acl-actions">
                    <a href="admin-client-edit.php?id=2">Edit</a>
                    <span aria-hidden="true">/</span>
                    <a
                      href="https://client-two.example"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View website</a>
                  </div>
                </td>
              </tr>

              <tr data-client-row>
                <td>
                  <span class="acl-logo acl-logo-pink">VD</span>
                </td>
                <td>Vision Dental Hospital</td>
                <td>
                  <a
                    href="https://client-three.example"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="acl-website"
                  >
                    https://client-three.example
                  </a>
                </td>
                <td>
                  <div class="acl-actions">
                    <a href="admin-client-edit.php?id=3">Edit</a>
                    <span aria-hidden="true">/</span>
                    <a
                      href="https://client-three.example"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View website</a>
                  </div>
                </td>
              </tr>

              <tr data-client-row>
                <td>
                  <span class="acl-logo acl-logo-gold">VS</span>
                </td>
                <td>V &amp; V Salon</td>
                <td>
                  <a
                    href="https://client-four.example"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="acl-website"
                  >
                    https://client-four.example
                  </a>
                </td>
                <td>
                  <div class="acl-actions">
                    <a href="admin-client-edit.php?id=4">Edit</a>
                    <span aria-hidden="true">/</span>
                    <a
                      href="https://client-four.example"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View website</a>
                  </div>
                </td>
              </tr>

              <tr data-client-row>
                <td>
                  <span class="acl-logo acl-logo-blue">BC</span>
                </td>
                <td>Bhavi Creations</td>
                <td>
                  <a
                    href="https://client-five.example"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="acl-website"
                  >
                    https://client-five.example
                  </a>
                </td>
                <td>
                  <div class="acl-actions">
                    <a href="admin-client-edit.php?id=5">Edit</a>
                    <span aria-hidden="true">/</span>
                    <a
                      href="https://client-five.example"
                      target="_blank"
                      rel="noopener noreferrer"
                    >View website</a>
                  </div>
                </td>
              </tr>

              <tr id="aclEmptyRow" hidden>
                <td colspan="4" class="acl-empty">
                  No clients found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="acl-note">
          New clients appear in this list after they are added.
        </p>

        <footer class="acl-footer">
          <span>
            Bhavi Creations / Admin / UI/UX concept - sample data
          </span>
          <span>07 / Desktop</span>
        </footer>
      </div>
    </div>

  </div>
</section>


<script>
(() => {
  const section = document.querySelector(".admin-clilient");
  if (!section) return;

  const search = section.querySelector("#aclClientSearch");
  const rows = [...section.querySelectorAll("[data-client-row]")];
  const count = section.querySelector("#aclClientCount");
  const emptyRow = section.querySelector("#aclEmptyRow");

  search.addEventListener("input", () => {
    const query = search.value.trim().toLowerCase();
    let visibleCount = 0;

    rows.forEach((row) => {
      const clientName = row.cells[1].textContent.toLowerCase();
      const website = row.cells[2].textContent.toLowerCase();
      const matches =
        clientName.includes(query) || website.includes(query);

      row.hidden = !matches;

      if (matches) visibleCount++;
    });

    count.textContent =
      `${visibleCount} ${visibleCount === 1 ? "client" : "clients"}`;

    emptyRow.hidden = visibleCount !== 0;
  });
})();

</script>


<?php include 'footer.php'; ?>