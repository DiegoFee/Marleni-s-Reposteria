document.addEventListener('alpine:init', () => {
    Alpine.data('marleniSidebar', () => ({
        sidebarWidth: 288,
        sidebarHidden: false,
        isResizing: false,
        startX: 0,
        startWidth: 288,
        minimumWidth: 220,
        maximumWidth: 380,

        init() {
            try {
                const storedWidth = Number.parseInt(window.localStorage.getItem('marleni-sidebar-width') ?? '', 10);

                if (Number.isFinite(storedWidth)) {
                    this.sidebarWidth = this.clampWidth(storedWidth);
                }

                this.sidebarHidden = window.localStorage.getItem('marleni-sidebar-hidden') === 'true';
            } catch {
                // Private browsing modes can block localStorage without affecting navigation.
            }
        },

        clampWidth(width) {
            return Math.min(this.maximumWidth, Math.max(this.minimumWidth, width));
        },

        setSidebarWidth(width) {
            this.sidebarWidth = this.clampWidth(Number(width));
        },

        persistSidebarWidth() {
            try {
                window.localStorage.setItem('marleni-sidebar-width', String(this.sidebarWidth));
            } catch {
                // The sidebar remains usable when storage is unavailable.
            }
        },

        startResize(event) {
            if (event.pointerType === 'touch') {
                return;
            }

            this.isResizing = true;
            this.startX = event.clientX;
            this.startWidth = this.sidebarWidth;
        },

        resize(event) {
            if (! this.isResizing) {
                return;
            }

            this.setSidebarWidth(this.startWidth + (event.clientX - this.startX));
        },

        stopResize() {
            if (! this.isResizing) {
                return;
            }

            this.isResizing = false;
            this.persistSidebarWidth();
        },

        resizeBy(amount) {
            this.setSidebarWidth(this.sidebarWidth + amount);
            this.persistSidebarWidth();
        },

        hideSidebar() {
            this.sidebarHidden = true;

            try {
                window.localStorage.setItem('marleni-sidebar-hidden', 'true');
            } catch {
                // The reveal handle remains available for the current page.
            }
        },

        showSidebar() {
            this.sidebarHidden = false;

            try {
                window.localStorage.setItem('marleni-sidebar-hidden', 'false');
            } catch {
                // The sidebar can still be reopened for the current page.
            }
        },
    }));
});
