"use client";

export default function ErrorPage({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <main className="error-page">
      <div className="error-card">
        <span className="error-mark">!</span>
        <p className="section-kicker">WE HIT A SMALL SNAG</p>
        <h1>We couldn’t load this page.</h1>
        <p>{error.message || "Please try again in a moment."}</p>
        <button className="button button-primary" onClick={reset}>Try again <span aria-hidden="true">↻</span></button>
      </div>
    </main>
  );
}
