document.addEventListener("DOMContentLoaded", function () {
  // =========================================================
  // 1. INJECT LOADER HTML & CSS (Professional "Breathing" Effect)
  // =========================================================
  const loaderHTML = `
      <div id="global-loader">
          <div class="loader-content">
              <img src="../assets/slate.png" alt="Loading..." class="loader-logo">
              <div class="loader-text">SYNCING DATA...</div>
          </div>
      </div>
  `;
  document.body.insertAdjacentHTML("afterbegin", loaderHTML);

  const loaderStyle = document.createElement("style");
  loaderStyle.innerHTML = `
      /* LOADER CONTAINER */
      #global-loader {
          position: fixed;
          top: 0; left: 0; width: 100%; height: 100%;
          background-color: #ffffff; /* FORCE WHITE BACKGROUND */
          z-index: 99999;
          display: flex; justify-content: center; align-items: center;
          transition: opacity 0.5s ease, visibility 0.5s;
          opacity: 1; visibility: visible;
      }

      /* CONTENT WRAPPER */
      .loader-content {
          text-align: center;
          display: flex;
          flex-direction: column;
          align-items: center;
      }

      /* LOGO ANIMATION (BREATHING + BLUR - NO ROTATION) */
      .loader-logo {
          width: 85px;
          height: auto;
          margin-bottom: 20px;
          animation: logo-breathe 2.5s infinite ease-in-out;
      }

      /* TEXT STYLE */
      .loader-text {
          font-family: 'Segoe UI', sans-serif;
          font-weight: 700;
          font-size: 13px;
          letter-spacing: 3px;
          color: #0d6efd; /* Primary Blue */
          text-transform: uppercase;
          opacity: 0.8;
          animation: text-fade 2.5s infinite ease-in-out;
      }

      /* HIDE STATE */
      #global-loader.hidden {
          opacity: 0;
          visibility: hidden;
      }

      /* KEYFRAMES */
      @keyframes logo-breathe {
          0% { transform: scale(1); filter: blur(0px); opacity: 1; }
          50% { transform: scale(1.08); filter: blur(1.5px); opacity: 0.85; } /* Slight Blur */
          100% { transform: scale(1); filter: blur(0px); opacity: 1; }
      }

      @keyframes text-fade {
          0%, 100% { opacity: 0.8; }
          50% { opacity: 0.4; }
      }
  `;
  document.head.appendChild(loaderStyle);

  // =========================================
  // 2. SIDEBAR TOGGLE
  // =========================================
  const hamburger = document.getElementById("hamburger");
  const body = document.body;

  if (hamburger) {
    hamburger.addEventListener("click", () => {
      body.classList.toggle("sidebar-closed");
    });
  }

  // =========================================
  // 3. DARK MODE (PERSISTENT)
  // =========================================
  const themeToggle = document.getElementById("themeToggle");

  if (localStorage.getItem("theme") === "dark") {
    body.classList.add("dark-mode");
    if (themeToggle) themeToggle.checked = true;
  }

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
  // 4. ALERTS & TOASTS MANAGER
  // =========================================
  const urlParams = new URLSearchParams(window.location.search);
  const isDarkMode = document.body.classList.contains("dark-mode");

  // A. STANDARD TOAST (For updates, creation)
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true,
    background: isDarkMode ? "#1e1e1e" : "#fff",
    color: isDarkMode ? "#fff" : "#333",
  });

  // B. ✨ PROFESSIONAL LOGIN SUCCESS ALERT (Center Screen)
  if (urlParams.has("login_success")) {
    // Try to grab the user's name from the header (if it exists in your PHP DOM)
    // Assumes structure: <span class="..."><?= $_SESSION['full_name'] ?></span>
    const userNameElement = document.querySelector(".dropdown-toggle span");
    const userName = userNameElement
      ? userNameElement.innerText.trim()
      : "User";

    Swal.fire({
      title: `<span style="font-weight: 300;">Welcome back,</span> <br><b>${userName}</b>`,
      html: '<span class="text-muted" style="font-size: 0.9rem;">SLATE FREIGHT SYSTEM IS READY</span>',
      imageUrl: "../assets/slate.png",
      imageWidth: 80,
      imageHeight: "auto",
      imageAlt: "Slate Logo",

      timer: 2500, // Auto close after 2.5 seconds
      timerProgressBar: true,
      showConfirmButton: false,

      // Theme Styling
      background: isDarkMode ? "#1e1e1e" : "#ffffff",
      color: isDarkMode ? "#e0e0e0" : "#333333",
      backdrop: `rgba(0,0,0,0.4)`, // Dim background slightly
      padding: "2rem",
      customClass: {
        popup: "animated fadeInDown", // Smooth entry
      },
    });
    cleanUrl();
  }

  // C. STANDARD ALERTS
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

  // =========================================================
  // 5. LOADER LOGIC
  // =========================================================
  const loader = document.getElementById("global-loader");

  // Hide loader after delay
  setTimeout(() => {
    loader.classList.add("hidden");
  }, 700); // 0.7s to let the user see the branding

  // Show loader on navigation
  document.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", function (e) {
      const target = this.getAttribute("href");

      if (
        !target ||
        target.startsWith("#") ||
        target.startsWith("javascript") ||
        this.getAttribute("target") === "_blank" ||
        e.ctrlKey ||
        e.metaKey
      ) {
        return;
      }

      loader.classList.remove("hidden");
    });
  });
});

// =========================================================
// 6. BACK BUTTON FIX
// =========================================================
window.addEventListener("pageshow", function (event) {
  if (event.persisted) {
    const loader = document.getElementById("global-loader");
    if (loader) loader.classList.add("hidden");
  }
});

// =========================================
// 7. GLOBAL CONFIRMATIONS
// =========================================

function confirmLogout() {
  const isDarkMode = document.body.classList.contains("dark-mode");

  Swal.fire({
    title: "Ready to leave?",
    text: "Session will be closed.",
    imageUrl: "../assets/slate.png",
    imageWidth: 60,

    background: isDarkMode ? "#1e1e1e" : "#ffffff",
    color: isDarkMode ? "#e0e0e0" : "#333333",

    showCancelButton: true,
    confirmButtonColor: "#dc3545",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Sign Out",
    cancelButtonText: "Stay",
    reverseButtons: true,
    focusCancel: true,
  }).then((result) => {
    if (result.isConfirmed) {
      const loader = document.getElementById("global-loader");
      if (loader) {
        // Change text for logout
        loader.querySelector(".loader-text").innerText = "CLOSING SESSION...";
        loader.classList.remove("hidden");
      }
      window.location.href = "../login/logout.php";
    }
  });
}

function confirmFormAction(event, title = "Are you sure?", icon = "warning") {
  event.preventDefault();
  const form = event.target;
  const isDarkMode = document.body.classList.contains("dark-mode");

  Swal.fire({
    title: title,
    icon: icon,
    background: isDarkMode ? "#1e1e1e" : "#ffffff",
    color: isDarkMode ? "#e0e0e0" : "#333333",
    showCancelButton: true,
    confirmButtonColor: "#3085d6",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, proceed",
  }).then((result) => {
    if (result.isConfirmed) {
      const loader = document.getElementById("global-loader");
      if (loader) loader.classList.remove("hidden");
      form.submit();
    }
  });
}
