/*
 * Small confirmation/info modal reusing the Settings dialog styling, used when
 * an admin/teacher taps the phase status badge (e.g. "Not Configured").
 * `confirmLabel`/`onConfirm` are optional — omit them for a pure info popup.
 */
export default function PhaseStatusDialog({
  title,
  message,
  confirmLabel = null,
  onConfirm = null,
  onClose,
}) {
  return (
    <div className="settings-modal-overlay" onClick={onClose}>
      <div
        className="settings-modal"
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="settings-modal-header">
          <div>
            <h3>{title}</h3>
            <p>Election phase status</p>
          </div>
          <button type="button" className="settings-modal-close" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </div>
        <div className="settings-modal-body">
          <p className="settings-modal-confirm-text">{message}</p>
        </div>
        <div className="settings-modal-footer">
          <button type="button" className="btn-secondary" onClick={onClose}>
            {confirmLabel ? 'Cancel' : 'Close'}
          </button>
          {confirmLabel && onConfirm && (
            <button type="button" className="btn-save" onClick={onConfirm}>
              {confirmLabel}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}