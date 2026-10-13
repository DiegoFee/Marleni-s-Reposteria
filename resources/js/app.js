document.addEventListener('alpine:init', () => {
    Alpine.data('customerPhoneInput', () => ({
        format(event) {
            const input = event.target;

            if (! (input instanceof HTMLInputElement)) {
                return;
            }

            const value = input.value;

            if (! /^[0-9\s-]*$/.test(value)) {
                return;
            }

            const digits = value.replace(/[\s-]/g, '');

            if (digits.length > 8) {
                return;
            }

            const digitPosition = value.slice(0, input.selectionStart ?? value.length).replace(/[\s-]/g, '').length;
            const formatted = digits.length > 4
                ? `${digits.slice(0, 4)}-${digits.slice(4)}`
                : digits;

            if (formatted === value) {
                return;
            }

            input.value = formatted;
            input.dispatchEvent(new Event('input', { bubbles: true }));

            if (document.activeElement === input && input.selectionStart !== null) {
                const caretPosition = digitPosition > 4 ? digitPosition + 1 : digitPosition;
                input.setSelectionRange(caretPosition, caretPosition);
            }
        },
    }));

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
                // -----------------------------------------------------------
                // Logica critica: La navegacion sigue disponible aunque el navegador bloquee localStorage.
                // -----------------------------------------------------------
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
                // -----------------------------------------------------------
                // Logica critica: El ancho ajustado se mantiene en la pagina actual aunque falle localStorage.
                // -----------------------------------------------------------
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
                // -----------------------------------------------------------
                // Logica critica: El control para volver a mostrar la barra sigue disponible en esta pagina.
                // -----------------------------------------------------------
            }
        },

        showSidebar() {
            this.sidebarHidden = false;

            try {
                window.localStorage.setItem('marleni-sidebar-hidden', 'false');
            } catch {
                // -----------------------------------------------------------
                // Logica critica: La barra puede volver a abrirse en esta pagina aunque no se guarde el cambio.
                // -----------------------------------------------------------
            }
        },
    }));
});
