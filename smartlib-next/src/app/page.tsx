import Link from "next/link";

const highlights = [
  {
    number: "01",
    title: "A library that feels alive",
    description:
      "A clear view of your reading, requests, and the books waiting to be discovered.",
  },
  {
    number: "02",
    title: "Your account, your activity",
    description:
      "Private borrowing and reservation records, protected by database-level access rules.",
  },
  {
    number: "03",
    title: "Made for the whole school",
    description:
      "A modern library workspace for students, teachers, and the staff who support them.",
  },
];

export default function Home() {
  return (
    <main className="landing">
      <nav className="landing-nav">
        <Link className="brand" href="/">
          <span className="brand-icon" aria-hidden="true">S</span>
          <span>SmartLib <b>Pro</b></span>
        </Link>
        <div className="nav-actions">
          <Link className="nav-link" href="/login">Member sign in</Link>
          <Link className="button button-dark" href="/login">Open your library <span aria-hidden="true">↗</span></Link>
        </div>
      </nav>

      <section className="landing-hero">
        <div className="hero-copy">
          <div className="hero-kicker"><span /> THE SCHOOL LIBRARY, REIMAGINED</div>
          <h1>More than books.<br /><em>A world to explore.</em></h1>
          <p>
            Find your next favorite, keep track of what you borrow, and stay
            connected to the heart of your school library.
          </p>
          <div className="hero-actions">
            <Link className="button button-primary" href="/login">Explore the library <span aria-hidden="true">→</span></Link>
            <span className="secure-note"><span aria-hidden="true">✦</span> A calmer way to discover</span>
          </div>
          <div className="hero-proof">
            <div className="avatar-stack" aria-hidden="true"><span>R</span><span>A</span><span>M</span><span>+</span></div>
            <div><strong>One space for every reader</strong><small>Thoughtfully designed for your school community</small></div>
          </div>
        </div>

        <div className="hero-art" aria-label="Illustration of books in a school library">
          <div className="art-glow" />
          <div className="floating-note note-top"><span>✦</span><div><b>Find your next read</b><small>Thousands of pages await</small></div></div>
          <div className="book-stage">
            <div className="book book-one"><span>THE ART<br />OF READING</span><small>FIELD NOTES · 2025</small></div>
            <div className="book book-two"><span>WILD<br />HORIZONS</span><small>A NOVEL</small></div>
            <div className="book book-three"><span>THE<br />BLUE HOUR</span><small>STORIES</small></div>
            <div className="book book-four"><span>GROW<br />THROUGH IT</span><small>DISCOVER</small></div>
            <div className="plant"><i /><i /><i /><b /></div>
            <div className="stage-base" />
          </div>
          <div className="floating-note note-bottom"><span className="note-check">✓</span><div><b>Your reading, in one place</b><small>Loans · Holds · Course books</small></div></div>
          <div className="hero-caption">A LITTLE MORE WONDER IN EVERY DAY <span>✳</span></div>
        </div>
      </section>

      <section className="intro-strip">
        <p>YOUR LIBRARY, WITHOUT THE GUESSWORK</p>
        <span>Discover <b>·</b> Borrow <b>·</b> Keep learning</span>
      </section>

      <section className="highlight-grid" aria-label="Library benefits">
        {highlights.map((item) => (
          <article className="highlight-card" key={item.number}>
            <span className="highlight-number">{item.number}</span>
            <h2>{item.title}</h2>
            <p>{item.description}</p>
            <span className="highlight-arrow" aria-hidden="true">↗</span>
          </article>
        ))}
      </section>

      <footer className="landing-footer">
        <Link className="brand" href="/"><span className="brand-icon" aria-hidden="true">S</span><span>SmartLib <b>Pro</b></span></Link>
        <span>Built for curious minds and the libraries that inspire them.</span>
        <Link href="/login">Get started <span aria-hidden="true">→</span></Link>
      </footer>
    </main>
  );
}
