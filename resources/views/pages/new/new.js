Alpine.data('uploadHandler', () => ({
    localFiles: [],
    uploading: false,
    successCount: 0,
    failureCount: 0,

    handleDragOver(e) {
        if (!@js(Auth::user()->subscribed()) && e.dataTransfer.items.length > 1) {
            e.dataTransfer.dropEffect = 'none';
            e.target.classList.add('!border-red-400');
            Flux.toast({
                heading: 'Selection Error',
                text: 'Upgrade to select more than one file at a time.',
                variant: 'danger',
            });
            return;
        }

        e.dataTransfer.dropEffect = 'move';
        e.target.classList.add('!border-accent');
    },

    handleDragLeave(event) {
        event.target.classList.remove('!border-accent', '!border-red-400');
    },

    onFileDropped(event) {
        this._addFiles(event.dataTransfer.files);
        event.target.classList.remove('!border-accent');
    },

    onFileInputChanged(event) {
        this._addFiles(event.target.files);
    },

    remove(index) {
        const file = this.localFiles.splice(index, 1)[0];
        this.$wire.removeFile(index);

        // Update success/failure counts
        if (file.error) {
            this.failureCount--;
        } else {
            this.successCount--;
        }

        // Clear file input if no files are left
        if (this.localFiles.length === 0) {
            document.querySelector('#fileInput').value = '';
        }
    },

    clear() {
        for (let i = 0; i < this.localFiles.length; i++) {
            this.$wire.cancelUpload('uploadedFiles.' + i);
        }
        this.localFiles = [];
        this.$wire.clearFiles();
        this.successCount = this.failureCount = 0;
        // Clear file input
        document.querySelector('#fileInput').value = '';
    },

    _addFiles(files) {
        if (files.length === 0) return;

        this.uploading = true;

        const fileArray = Array.from(files);

        Promise.allSettled(
            fileArray.map((file, index) => {
                // Add to local preview immediately
                const { index: localIndex, error } = this._initFileItem(file);

                if (error) {
                    this.failureCount++;
                    return Promise.reject('Invalid file type or size');
                }

                // Upload the file
                return this._uploadFile(localIndex, file);
            }),
        )
            .then(() => {
                this.uploading = false;
            })
            .catch(() => {
                this.uploading = false;
            });
    },

    /**
        * Creates and adds a new file item to localFiles array.
        * @param {File} file - The File object to add.
        * @returns {Object<index: number, error: boolean>}
        */
    _initFileItem(file) {
        const allowedTypes = [
            'audio/wav',
            'audio/x-wav',
            'audio/mpeg',
            'audio/mp3',
            'audio/mp4',
            'audio/aac',
            'audio/ogg',
            'audio/webm',
            'audio/flac',
        ];
        const maxFileSize = 25 * 1024 * 1024; // 25MB

        // Validate file type and size
        const isAudioFile =
            allowedTypes.includes(file.type) || file.name.match(/\.(mp3|wav|ogg|aac|m4a|flac)$/i) !== null;
        const isValidSize = file.size <= maxFileSize;

        this.localFiles.push({
            name: file.name,
            type: file.type,
            size: file.size,
            uploaded: false,
            error: !isAudioFile || !isValidSize,
            errorMessage: !isAudioFile
                ? 'Unsupported file type.'
                : !isValidSize
                    ? 'File exceeds maximum size of 25MB'
                    : '',
        });

        return { index: this.localFiles.length - 1, error: !isAudioFile || !isValidSize };
    },

    /**
        * Upload file and handle callbacks.
        * @param {File} file - The File object to upload.
        */
    _uploadFile(index, file) {
        return new Promise((resolve, reject) => {
            this.$wire.upload(
                'uploadedFiles.' + index,
                file,
                (uploadedFilename) => {
                    // Pass name/path to info array
                    this.$wire.addFileInfo(file.name, uploadedFilename);
                    this.localFiles[index].uploaded = true;
                    this.successCount++;
                    resolve('Upload successful');
                },
                (error) => {
                    this.localFiles[index].error = true;
                    this.localFiles[index].errorMessage = error;
                    this.failureCount++;
                    reject('Upload failed');
                },
            );
        });
    },
}));
