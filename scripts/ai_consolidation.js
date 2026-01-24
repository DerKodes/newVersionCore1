// 1. MOCK DATA
const historyData = {
  distances: [10, 20, 40, 60, 100, 150],
  times: [30, 45, 80, 110, 160, 240],
};

async function trainPredictiveModel() {
  return tf.tidy(() => {
    const model = tf.sequential();
    model.add(tf.layers.dense({ units: 1, inputShape: [1] }));
    model.compile({ loss: "meanSquaredError", optimizer: tf.train.adam(0.01) });
    return model;
  });
}

async function trainModel(model) {
  const xs = tf.tensor2d(historyData.distances, [6, 1]);
  const ys = tf.tensor2d(historyData.times, [6, 1]);
  await model.fit(xs, ys, { epochs: 50 });
  xs.dispose();
  ys.dispose();
  return model;
}

// 3. GEOCODING HELPER WITH CACHE
const geoCache = {};

async function geocodeAddress(address) {
  if (geoCache[address]) return geoCache[address];

  try {
    const url = `../api/geocode.php?q=${encodeURIComponent(address)}`;
    const response = await fetch(url);
    const text = await response.text();

    try {
      const data = JSON.parse(text);
      let result = null;

      if (Array.isArray(data) && data.length > 0) {
        result = { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
      } else if (data.lat && data.lon) {
        result = { lat: parseFloat(data.lat), lng: parseFloat(data.lon) };
      }

      if (result) {
        geoCache[address] = result;
        return result;
      }
    } catch (e) {
      console.error("JSON error", e);
    }
  } catch (error) {
    console.error(error);
  }
  return null;
}

// 4. MAIN FUNCTION
async function runAiOptimization() {
  const statusDiv = document.getElementById("aiStatus");
  const loadingSpinner = document.getElementById("aiLoading");

  if (typeof tf === "undefined") {
    statusDiv.innerHTML = "Error: TF.js missing.";
    return;
  }

  if (loadingSpinner) loadingSpinner.style.display = "inline-block";
  statusDiv.innerHTML = "Gathering shipments...";

  // A. Gather Data
  const rows = document.querySelectorAll("#shipmentSelectionTable tbody tr");
  const shipments = [];

  rows.forEach((row) => {
    const originEl = row.querySelector(".origin-text");
    const destEl = row.querySelector(".destination-text");
    const checkbox = row.querySelector('input[type="checkbox"]');

    // Uncheck all initially
    if (checkbox) checkbox.checked = false;
    if (row) row.style.backgroundColor = "";

    if (originEl && destEl && checkbox) {
      let origin =
        originEl.getAttribute("data-address") || originEl.innerText.trim();
      let dest = destEl.getAttribute("data-address") || destEl.innerText.trim();

      if (!origin.toLowerCase().includes("philippines"))
        origin += ", Philippines";
      if (!dest.toLowerCase().includes("philippines")) dest += ", Philippines";

      shipments.push({
        element: row,
        checkbox: checkbox,
        originAddr: origin,
        destAddr: dest,
        originCoords: null,
        destCoords: null,
      });
    }
  });

  if (shipments.length < 2) {
    statusDiv.innerHTML = "Select 2+ shipments.";
    if (loadingSpinner) loadingSpinner.style.display = "none";
    return;
  }

  // B. Geocode Both Ends
  let validCount = 0;
  for (let [idx, s] of shipments.entries()) {
    statusDiv.innerHTML = `Geocoding ${idx + 1}/${shipments.length}...`;

    const [o, d] = await Promise.all([
      geocodeAddress(s.originAddr),
      geocodeAddress(s.destAddr),
    ]);

    s.originCoords = o;
    s.destCoords = d;

    if (o && d) validCount++;

    if (!geoCache[s.originAddr] || !geoCache[s.destAddr]) {
      await new Promise((r) => setTimeout(r, 400));
    }
  }

  const validShipments = shipments.filter(
    (s) => s.originCoords !== null && s.destCoords !== null,
  );

  if (validShipments.length < 2) {
    statusDiv.innerHTML = "Not enough valid addresses.";
    if (loadingSpinner) loadingSpinner.style.display = "none";
    return;
  }

  // C. K-Means (4 Dimensions)
  statusDiv.innerHTML = "Clustering by Route...";
  const assignments = tf.tidy(() => {
    const tensorData = tf.tensor2d(
      validShipments.map((s) => [
        s.originCoords.lat,
        s.originCoords.lng,
        s.destCoords.lat,
        s.destCoords.lng,
      ]),
    );
    const k = Math.min(2, validShipments.length);

    const indices = Array.from({ length: validShipments.length }, (_, i) => i)
      .sort(() => Math.random() - 0.5)
      .slice(0, k);
    let centroids = tf.gather(tensorData, indices);

    for (let i = 0; i < 50; i++) {
      const dists = tensorData
        .expandDims(1)
        .sub(centroids.expandDims(0))
        .square()
        .sum(2);
      const nearest = dists.argMin(1);
      const newRows = [];
      for (let c = 0; c < k; c++) {
        const mask = nearest.equal(c).asType("float32").expandDims(1);
        const count = mask.sum();
        if (count.dataSync()[0] === 0)
          newRows.push(centroids.slice([c, 0], [1, 4]));
        else newRows.push(tensorData.mul(mask).sum(0).div(count).expandDims(0));
      }
      centroids = tf.concat(newRows, 0);
    }
    const finalDists = tensorData
      .expandDims(1)
      .sub(centroids.expandDims(0))
      .square()
      .sum(2);
    return finalDists.argMin(1).dataSync();
  });

  // D. Prediction & UI Update
  let model = await trainPredictiveModel();
  model = await trainModel(model);

  const colors = ["#e3f2fd", "#fff3e0", "#e8f5e9"];
  const sets = ["A", "B", "C"];
  const hub = { lat: 14.5995, lng: 120.9842 };

  const vehicleSelect = document.querySelector('select[name="vehicle_asset"]');
  let availableVehicles = [];
  if (vehicleSelect) {
    availableVehicles = Array.from(vehicleSelect.options).filter(
      (o) =>
        o.value &&
        !o.disabled &&
        ["truck", "van", "l300", "wing", "pickup"].some((k) =>
          o.text.toLowerCase().includes(k),
        ),
    );
    if (availableVehicles.length === 0)
      availableVehicles = Array.from(vehicleSelect.options).filter(
        (o) => o.value && !o.disabled,
      );
  }

  validShipments.forEach((s, idx) => {
    const gid = assignments[idx];
    s.element.style.backgroundColor = colors[gid % 3];

    // --- KEY FIX HERE ---
    // Only select items belonging to Group 0 (The first, cohesive group)
    // This ensures we don't select all data, only the "Best Match" trip.
    s.checkbox.checked = gid === 0;

    const dist =
      Math.sqrt(
        Math.pow(s.originCoords.lat - s.destCoords.lat, 2) +
          Math.pow(s.originCoords.lng - s.destCoords.lng, 2),
      ) * 111;

    const pred = model.predict(tf.tensor2d([dist], [1, 1]));
    let mins = pred.dataSync()[0].toFixed(0);
    if (isNaN(mins)) mins = "45";

    const assignedVehicle =
      availableVehicles.length > 0
        ? availableVehicles[gid % availableVehicles.length].text
        : "Generic";

    const cell = s.element.querySelector("td:nth-child(3)");
    if (cell.querySelector(".ai-badge"))
      cell.querySelector(".ai-badge").remove();

    cell.innerHTML += `<div class="badge bg-dark mt-1 ai-badge d-block text-start p-2">
            <div class="fw-bold text-warning"><i class="bi bi-geo-alt-fill"></i> Set ${sets[gid]} (Route)</div>
            <div class="small"><i class="bi bi-clock"></i> ~${mins} min</div>
            <div class="small text-info"><i class="bi bi-truck"></i> ${assignedVehicle}</div>
        </div>`;
  });

  if (vehicleSelect && availableVehicles.length > 0) {
    vehicleSelect.value = availableVehicles[0].value;
    vehicleSelect.style.border = "2px solid #198754";
  }

  if (loadingSpinner) loadingSpinner.style.display = "none";
  statusDiv.innerHTML =
    '<i class="bi bi-check-circle-fill text-success"></i> Best route selected!';
}
