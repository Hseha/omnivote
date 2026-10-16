import { Component } from 'react';

/*
 * Top-level render guard.
 *
 * Without one, any uncaught render/lifecycle error unmounts the whole React
 * tree and leaves the browser showing a blank white page with no clue why.
 * This boundary keeps a recoverable screen on the page instead, and offers a
 * reload so a transient failure (e.g. a dropped API response mid-render)
 * doesn't require the operator to know to hard-refresh.
 */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // Surface the real stack for support; the UI stays human-readable.
    console.error('Admin console crashed:', error, info?.componentStack);
  }

  handleReload = () => {
    window.location.reload();
  };

  render() {
    if (!this.state.error) return this.props.children;

    return (
      <div className="error-boundary-screen" role="alert">
        <div className="error-boundary-card">
          <h1>Something went wrong</h1>
          <p>
            The console hit an unexpected error and stopped rendering. Reloading usually
            fixes it — if it keeps happening, the API may be unreachable.
          </p>
          <pre className="error-boundary-detail">{String(this.state.error?.message || this.state.error)}</pre>
          <button type="button" className="error-boundary-btn" onClick={this.handleReload}>
            Reload console
          </button>
        </div>
      </div>
    );
  }
}
