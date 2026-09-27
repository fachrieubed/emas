<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form action="{{ route('admin.upload') }}" method="POST" enctype="multipart/form-data" class="modal-content" id="uploadForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload text-gold me-2"></i>Upload Data Emas Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small">
                    <i class="bi bi-info-circle me-1"></i>
                    Format kolom (CSV, pemisah <code>;</code>): <br>
                    <code>Tanggal;Terakhir;Pembukaan;Tertinggi;Terendah;Vol.;Perubahan%</code><br>
                    Contoh: <code>04/01/2021;1.048.965,76;1.049.364,58;1.049.890,23;1.045.652,15;92.33K;-0,10%</code>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">File CSV data emas</label>
                    <input type="file" class="form-control" name="file" accept=".csv,.txt,.tsv" required>
                    <div class="form-text">Maks. 10 MB. Dataset aktif akan diganti dengan data dari file ini.</div>
                </div>

                <div class="alert alert-warning border small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <b>Pastikan file berisi rentang tanggal yang lengkap</b> (mis. 2021 – sekarang, ribuan baris).
                    File yang cuma berisi sebagian (mis. 1 bulan saja) akan <b>menggantikan seluruh data lama</b>.
                </div>

                <hr>
                <h6 class="section-title mb-3"><i class="bi bi-sliders me-1"></i>Konfigurasi Model tsQRF</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Jumlah pohon (n_estimators)</label>
                        <input type="number" class="form-control" name="n_estimators" value="500" min="10" max="1000" required>
                        <div class="form-text">Default panduan: <b>500</b> decision tree</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">min_samples_leaf</label>
                        <input type="number" class="form-control" name="min_samples_leaf" value="5" min="1" max="50" required>
                        <div class="form-text">Default panduan: <b>5</b></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Split data training</label>
                        <input type="number" class="form-control" name="split" value="0.8" step="0.05" min="0.5" max="0.95" required>
                        <div class="form-text">Default: <b>0.80</b> (80% training)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Horizon peramalan (hari)</label>
                        <input type="number" class="form-control" name="horizon" value="7" min="1" max="30" required>
                        <div class="form-text">Berapa hari ke depan yang diramalkan.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">max_features</label>
                        <select class="form-select" name="max_features">
                            <option value="sqrt" selected>sqrt (default panduan)</option>
                            <option value="all">all (semua fitur)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <span class="text-muted small me-auto" id="uploadSpinner"><span class="spinner-border spinner-border-sm spin-gold me-1"></span>Upload & proses…</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-gold" id="btnUpload">
                    <i class="bi bi-play-fill me-1"></i>Upload & Proses
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('uploadForm').addEventListener('submit', function () {
        document.getElementById('uploadSpinner').style.display = 'inline';
        document.getElementById('btnUpload').disabled = true;
    });
</script>