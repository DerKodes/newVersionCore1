/**
 * Address Autocomplete using Nominatim (OpenStreetMap)
 * Automatically populates hidden Lat/Lng fields for database storage.
 */

function setupAutocomplete(inputId, hiddenAddressId, hiddenLatId, hiddenLngId) {
  const input = document.getElementById(inputId);
  const hiddenAddress = document.getElementById(hiddenAddressId);
  const hiddenLat = document.getElementById(hiddenLatId);
  const hiddenLng = document.getElementById(hiddenLngId);

  // Safety Check: If elements don't exist (e.g. on Dashboard page), stop running.
  if (!input || !hiddenAddress || !hiddenLat || !hiddenLng) {
    return;
  }

  // 1. Setup Wrapper for the dropdown list
  const wrapper = document.createElement("div");
  wrapper.style.position = "relative";
  input.parentNode.insertBefore(wrapper, input);
  wrapper.appendChild(input);

  // 2. Create the suggestion box (Hidden by default)
  let box = document.createElement("div");
  box.className = "list-group position-absolute w-100 shadow-sm";
  box.style.zIndex = "1000";
  box.style.maxHeight = "250px";
  box.style.overflowY = "auto";
  box.style.top = "100%";
  box.style.left = "0";
  box.style.display = "none"; // Hide initially
  wrapper.appendChild(box);

  let timeout = null;

  // 3. Listen for typing
  input.addEventListener("input", function () {
    const q = this.value.trim();

    // 🛑 CRITICAL: Clear hidden data immediately when user types
    // This forces them to click a suggestion to get valid coords.
    hiddenAddress.value = "";
    hiddenLat.value = "";
    hiddenLng.value = "";

    // Remove "Valid" green border if it exists
    input.classList.remove("is-valid");

    clearTimeout(timeout);

    // Hide box if input is too short
    if (q.length < 3) {
      box.style.display = "none";
      box.innerHTML = "";
      return;
    }

    // Show "Searching..." indicator
    box.style.display = "block";
    box.innerHTML = `<div class="list-group-item text-muted small">Searching...</div>`;

    // 4. Fetch Data (Debounced 500ms)
    timeout = setTimeout(() => {
      fetch(
        `https://nominatim.openstreetmap.org/search?format=json&limit=5&countrycodes=ph&q=${encodeURIComponent(q)}`,
        {
          headers: {
            "User-Agent": "SlateFreightSystem/1.0 (Student Project)", // Politeness policy
          },
        },
      )
        .then((res) => {
          if (!res.ok) throw new Error("Network error");
          return res.json();
        })
        .then((data) => {
          box.innerHTML = "";

          if (data.length === 0) {
            box.innerHTML = `<div class="list-group-item text-muted small">No address found</div>`;
            return;
          }

          // 5. Render Suggestions
          data.forEach((place) => {
            let item = document.createElement("button");
            item.type = "button"; // Prevent form submission
            item.className =
              "list-group-item list-group-item-action text-start";

            // Format: Bold main name, smaller full address
            const mainName = place.display_name.split(",")[0];
            item.innerHTML = `
                <div class="fw-bold">${mainName}</div>
                <div class="text-muted small" style="font-size:0.85em">${place.display_name}</div>
            `;

            // 6. Handle Selection
            item.onclick = () => {
              // Fill visible input
              input.value = place.display_name;

              // Fill hidden DB fields
              hiddenAddress.value = place.display_name;
              hiddenLat.value = place.lat;
              hiddenLng.value = place.lon;

              // Visual Success Indicator
              input.classList.add("is-valid");

              // Hide box
              box.innerHTML = "";
              box.style.display = "none";
            };

            box.appendChild(item);
          });
        })
        .catch((err) => {
          console.error(err);
          box.innerHTML = `<div class="list-group-item text-danger small">Connection Error</div>`;
        });
    }, 500); // Wait 500ms after typing stops
  });

  // 7. Hide box when clicking outside
  document.addEventListener("click", function (e) {
    if (!wrapper.contains(e.target)) {
      box.style.display = "none";
    }
  });
}

// ================= INITIALIZE =================
document.addEventListener("DOMContentLoaded", function () {
  // Setup Origin Field
  setupAutocomplete(
    "origin_search", // The visible input ID
    "origin_address", // The hidden address ID
    "origin_lat", // The hidden latitude ID
    "origin_lng", // The hidden longitude ID
  );

  // Setup Destination Field
  setupAutocomplete(
    "destination_search",
    "destination_address",
    "destination_lat",
    "destination_lng",
  );
});
