// 1. MOCK DATA (Normalized for stability)
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

// 3. GEOCODING HELPER
async function geocodeAddress(address) {
  try {
    const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(address)}`;
    const response = await fetch(url, {
      headers: { "User-Agent": "LogisticsApp/1.0" },
    });
    const data = await response.json();
    if (data && data.length > 0)
      return { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
  } catch (error) {
    console.error(error);
  }
  return null;
}

// 4. MAIN FUNCTION
async function runAiOptimization() {
  const statusDiv = document.getElementById("aiStatus");
  if (typeof tf === "undefined") {
    statusDiv.innerHTML = "Error: TF.js missing.";
    return;
  }

  statusDiv.innerHTML =
    '<span class="spinner-border spinner-border-sm"></span> AI Thinking...';

  // A. Gather Data
  const rows = document.querySelectorAll("#shipmentSelectionTable tbody tr");
  const shipments = [];

  for (let i = 0; i < rows.length; i++) {
    // FIX: TARGET DESTINATION INSTEAD OF ORIGIN
    const destDiv = rows[i].querySelector(".destination-text");
    const checkbox = rows[i].querySelector('input[type="checkbox"]');

    if (destDiv && checkbox) {
      shipments.push({
        element: rows[i],
        checkbox: checkbox,
        address: destDiv.innerText.trim(), // Group by Destination
        coords: null,
      });
    }
  }

  if (shipments.length === 0) {
    statusDiv.innerHTML = "No shipments.";
    return;
  }

  // B. Geocode
  let validCount = 0;
  for (let [idx, s] of shipments.entries()) {
    statusDiv.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Geocoding ${idx + 1}/${shipments.length}...`;
    let search = s.address.toLowerCase().includes("philippines")
      ? s.address
      : s.address + ", Philippines";
    s.coords = await geocodeAddress(search);
    if (s.coords) validCount++;
    await new Promise((r) => setTimeout(r, 800));
  }

  const validShipments = shipments.filter((s) => s.coords !== null);
  if (validShipments.length < 2) {
    statusDiv.innerHTML = "Need 2+ valid addresses.";
    return;
  }

  // C. K-Means Clustering
  statusDiv.innerHTML = "Clustering...";
  const assignments = tf.tidy(() => {
    const tensorData = tf.tensor2d(
      validShipments.map((s) => [s.coords.lat, s.coords.lng]),
    );
    const k = Math.min(2, validShipments.length);

    const indices = Array.from({ length: validShipments.length }, (_, i) => i)
      .sort(() => Math.random() - 0.5)
      .slice(0, k);
    let centroids = tf.gather(tensorData, indices);

    for (let i = 0; i < 30; i++) {
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
          newRows.push(centroids.slice([c, 0], [1, 2]));
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
  const hub = { lat: 14.5995, lng: 120.9842 }; // Manila

  const groupAddresses = { 0: [], 1: [], 2: [] };

  // E. Vehicle Selection
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
    groupAddresses[gid].push(s.address);

    s.element.style.backgroundColor = colors[gid % 3];
    s.checkbox.checked = gid === 0;

    const dist =
      Math.sqrt(
        Math.pow(s.coords.lat - hub.lat, 2) +
          Math.pow(s.coords.lng - hub.lng, 2),
      ) * 111;
    const pred = model.predict(tf.tensor2d([dist], [1, 1]));
    let mins = pred.dataSync()[0].toFixed(0);
    if (isNaN(mins)) mins = "45";

    const assignedVehicle =
      availableVehicles.length > 0
        ? availableVehicles[gid % availableVehicles.length].text
        : "Generic";

    const cell = s.element.querySelector("td:nth-child(2)");
    if (cell.querySelector(".ai-badge"))
      cell.querySelector(".ai-badge").remove();

    cell.innerHTML += `<div class="badge bg-dark mt-1 ai-badge d-block text-start p-2">
            <div class="fw-bold text-warning"><i class="bi bi-geo-alt-fill"></i> Set ${sets[gid]} (Dest)</div>
            <div class="small"><i class="bi bi-clock"></i> ~${mins} min</div>
            <div class="small text-info"><i class="bi bi-truck"></i> ${assignedVehicle}</div>
        </div>`;
  });

  if (vehicleSelect && availableVehicles.length > 0) {
    vehicleSelect.value = availableVehicles[0].value;
    vehicleSelect.style.border = "2px solid #198754";
  }

  statusDiv.innerHTML =
    '<i class="bi bi-check-circle-fill text-success"></i> Grouped by Destination!';
}
