document.addEventListener("DOMContentLoaded", function () {
  // =========================================
  // 1. SIDEBAR TOGGLE
  // =========================================
  const hamburger = document.getElementById("hamburger");
  const body = document.body;

  if (hamburger) {
    hamburger.addEventListener("click", () => {
      body.classList.toggle("sidebar-closed");
    });
  }

  // =========================================
  // 2. DARK MODE (PERSISTENT)
  // =========================================
  const themeToggle = document.getElementById("themeToggle");

  // Check saved preference on load
  if (localStorage.getItem("theme") === "dark") {
    body.classList.add("dark-mode");
    if (themeToggle) themeToggle.checked = true;
  }

  // Toggle listener
  if (themeToggle) {
    themeToggle.addEventListener("change", () => {
      if (themeToggle.checked) {
        body.classList.add("dark-mode");
        localStorage.setItem("theme", "dark");
      } else {
        body.classList.remove("dark-mode");
        localStorage.setItem("theme", "light");
      }
    });
  }

  // =========================================
  // 3. SWEETALERT: SUCCESS TOASTS (URL PARAMS)
  // =========================================
  const urlParams = new URLSearchParams(window.location.search);

  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
  });

  if (urlParams.has("created")) {
    Toast.fire({ icon: "success", title: "Record created successfully!" });
    cleanUrl();
  }
  if (urlParams.has("updated")) {
    Toast.fire({ icon: "success", title: "Status updated successfully!" });
    cleanUrl();
  }
  if (urlParams.has("success")) {
    Toast.fire({ icon: "success", title: "Operation successful!" });
    cleanUrl();
  }
  if (urlParams.has("decon")) {
    Toast.fire({ icon: "warning", title: "Items deconsolidated." });
    cleanUrl();
  }

  function cleanUrl() {
    window.history.replaceState(null, null, window.location.pathname);
  }
});

// =========================================
// 4. GLOBAL SWEETALERT CONFIRMATIONS
// =========================================

// Standard Logout
function confirmLogout() {
  Swal.fire({
    title: "Logout?",
    text: "You will be returned to the login screen.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Yes, logout",
  }).then((result) => {
    if (result.isConfirmed) {
      window.location.href = "../login/logout.php";
    }
  });
}

// Generic Form Confirmation (Use onsubmit="return confirmAction(event, 'Message')"
function confirmFormAction(event, title = "Are you sure?", icon = "warning") {
  event.preventDefault(); // Stop form
  const form = event.target; // Get the form that triggered this

  Swal.fire({
    title: title,
    icon: icon,
    showCancelButton: true,
    confirmButtonColor: "#3085d6",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, proceed",
  }).then((result) => {
    if (result.isConfirmed) {
      form.submit(); // Submit purely if confirmed
    }
  });
}
