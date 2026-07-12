(function () {
    var dropZone = document.getElementById('drop-zone');
    var fileInput = document.getElementById('file-input');
    var cameraInput = document.getElementById('camera-input');
    var previewContainer = document.getElementById('preview-container');
    var previewImg = document.getElementById('preview-img');
    var previewName = document.getElementById('preview-name');
    var uploadBtn = document.getElementById('upload-btn');
    var uploadForm = document.getElementById('upload-form');

    if (!dropZone || !fileInput) return;

    function handleFile(file) {
        if (!file) return;

        // Show preview
        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewContainer.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        }

        previewName.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' Ko)';
        previewContainer.classList.remove('d-none');
        uploadBtn.disabled = false;
    }

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.classList.add('border-primary');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.classList.remove('border-primary');
        });
    });

    dropZone.addEventListener('drop', function (e) {
        var file = e.dataTransfer.files[0];
        if (file) {
            // Create a DataTransfer to set file input
            var dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            handleFile(file);
        }
    });

    fileInput.addEventListener('change', function () {
        if (fileInput.files[0]) handleFile(fileInput.files[0]);
    });

    if (cameraInput) {
        cameraInput.addEventListener('change', function () {
            if (cameraInput.files[0]) {
                // Copy to main file input
                var dt = new DataTransfer();
                dt.items.add(cameraInput.files[0]);
                fileInput.files = dt.files;
                handleFile(cameraInput.files[0]);
            }
        });
    }
})();
