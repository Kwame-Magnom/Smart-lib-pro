"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { signOut } from "./actions";
import { CancelReservationButton, ReservationButton } from "./library-actions";

export type ProfileRecord = {
  id: string;
  email: string;
  display_name: string;
  school_id: string | null;
  role: string;
  account_status: string;
};

export type BookRecord = {
  id: number;
  title: string;
  author: string;
  isbn: string | null;
  category: string;
  description: string | null;
  cover_image_url: string | null;
  shelf_code: string | null;
  book_copies: { status: string }[];
};

export type LoanRecord = {
  id: number;
  issued_at: string;
  due_at: string;
  status: string;
  book_copies: { barcode: string; books: { title: string; author: string } | null } | null;
};

export type ReservationRecord = {
  id: number;
  reserved_at: string;
  status: string;
  books: { title: string; author: string } | null;
};

function dateLabel(value: string) {
  const date = new Date(`${value.slice(0, 10)}T12:00:00`);
  return new Intl.DateTimeFormat("en", { month: "short", day: "numeric", year: "numeric" }).format(date);
}

function greetingName(profile: ProfileRecord) {
  return profile.display_name.trim() || profile.email.split("@")[0];
}

export default function LibraryWorkspace({
  profile,
  books,
  loans,
  reservations,
}: {
  profile: ProfileRecord;
  books: BookRecord[];
  loans: LoanRecord[];
  reservations: ReservationRecord[];
}) {
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("All books");
  const filteredBooks = useMemo(() => {
    const search = query.trim().toLowerCase();
    return books.filter((book) => {
      const matchesSearch = !search || [book.title, book.author, book.category, book.isbn ?? ""]
        .some((value) => value.toLowerCase().includes(search));
      return matchesSearch && (category === "All books" || book.category === category);
    });
  }, [books, category, query]);
  const categories = [...new Set(books.map((book) => book.category))].sort();
  const activeLoans = loans.filter((loan) => ["Active", "Overdue"].includes(loan.status));
  const overdueLoans = activeLoans.filter((loan) => loan.status === "Overdue" || loan.due_at < new Date().toISOString().slice(0, 10));
  const activeReservations = reservations.filter((reservation) => ["Pending", "Ready"].includes(reservation.status));
  const initials = greetingName(profile).split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase();
  const staffRoles = ["Super Administrator", "Administrator"];

  return (
    <div className="app-shell">
      <aside className="app-sidebar">
        <Link className="brand sidebar-brand" href="/"><span className="brand-icon" aria-hidden="true">S</span><span>SmartLib <b>Pro</b></span></Link>
        <p className="side-label">YOUR LIBRARY</p>
        <nav className="side-nav" aria-label="Library navigation">
          <a className="side-link active" href="#overview"><span>◈</span> Overview</a>
          <a className="side-link" href="#catalogue"><span>▤</span> Discover books</a>
          <a className="side-link" href="#my-loans"><span>◷</span> My borrowing</a>
          <a className="side-link" href="#reservations"><span>⌑</span> Reservations <small>{activeReservations.length}</small></a>
          {staffRoles.includes(profile.role) && <Link className="side-link" href="/admin"><span>✳</span> Staff workspace</Link>}
        </nav>
        <div className="sidebar-bottom">
          <div className="sidebar-help"><span>✦</span><b>Need a hand?</b><p>Your library team is here to help you find your next great read.</p></div>
          <form action={signOut}><button className="sidebar-signout" type="submit"><span>↪</span> Sign out</button></form>
        </div>
      </aside>

      <main className="library-main" id="overview">
        <header className="workspace-topbar">
          <div className="breadcrumb"><span>SmartLib</span><b>/</b> Member space</div>
          <div className="profile-menu"><span className="user-avatar">{initials || "M"}</span><div><strong>{greetingName(profile)}</strong><small>{profile.role}</small></div><span className="profile-caret">⌄</span></div>
        </header>

        <section className="welcome-panel">
          <div className="welcome-copy">
            <p className="hero-kicker"><span /> YOUR READING SPACE</p>
            <h1>Good to see you, {greetingName(profile).split(" ")[0]}.</h1>
            <p>Every great journey begins with a page. Find yours in the library today.</p>
            <a className="button button-light" href="#catalogue">Explore the collection <span aria-hidden="true">→</span></a>
          </div>
          <div className="welcome-art" aria-hidden="true"><div className="sun-disc" /><span className="sparkle sparkle-one">✳</span><span className="sparkle sparkle-two">✦</span><div className="welcome-book"><small>YOUR NEXT<br />CHAPTER</small><span>✷</span></div><div className="welcome-book-shadow" /></div>
          <span className="welcome-counter">A LITTLE WONDER, EVERY DAY <b>✳</b></span>
        </section>

        <section className="stat-grid" aria-label="Your library at a glance">
          <article className="stat-card"><div className="stat-top"><span className="stat-icon icon-lilac">↗</span><span className="stat-caption">IN YOUR CARE</span></div><strong>{activeLoans.length.toString().padStart(2, "0")}</strong><p>Books borrowed</p><small>Books currently checked out to you</small></article>
          <article className="stat-card"><div className="stat-top"><span className="stat-icon icon-peach">◷</span><span className="stat-caption">KEEP AN EYE ON</span></div><strong>{overdueLoans.length.toString().padStart(2, "0")}</strong><p>Past due</p><small>{overdueLoans.length ? "Please visit the library desk" : "You're all caught up"}</small></article>
          <article className="stat-card"><div className="stat-top"><span className="stat-icon icon-mint">⌑</span><span className="stat-caption">WAITING FOR YOU</span></div><strong>{activeReservations.length.toString().padStart(2, "0")}</strong><p>Active holds</p><small>Reservation requests in progress</small></article>
          <article className="stat-card stat-card-featured"><div className="stat-top"><span className="stat-icon icon-cream">✳</span><span className="stat-caption">ON THE SHELVES</span></div><strong>{books.length.toString().padStart(2, "0")}</strong><p>Titles to explore</p><small>A whole new story is waiting</small></article>
        </section>

        <section className="content-section" id="catalogue">
          <div className="section-heading">
            <div><p className="section-kicker">HANDPICKED FOR CURIOUS MINDS</p><h2>Find your next read</h2><p>Search titles, authors, or subjects across the school collection.</p></div>
            <a className="text-arrow" href="#catalogue">Browse collection <span aria-hidden="true">↗</span></a>
          </div>
          <div className="catalogue-tools">
            <label className="search-box"><span aria-hidden="true">⌕</span><input aria-label="Search books" placeholder="Search by title, author, subject…" value={query} onChange={(event) => setQuery(event.target.value)} /><kbd>⌘ K</kbd></label>
            <label className="category-select"><span className="sr-only">Filter by category</span><select value={category} onChange={(event) => setCategory(event.target.value)}><option>All books</option>{categories.map((item) => <option key={item}>{item}</option>)}</select><span aria-hidden="true">⌄</span></label>
          </div>
          {filteredBooks.length ? (
            <div className="book-grid">
              {filteredBooks.map((book, index) => {
                const available = book.book_copies.filter((copy) => copy.status === "Available").length;
                const physical = book.book_copies.filter((copy) => ["Available", "Borrowed", "Reserved"].includes(copy.status)).length;
                const artTone = ["cover-rose", "cover-blue", "cover-olive", "cover-amber"][index % 4];
                return (
                  <article className="book-card" key={book.id}>
                    <div className={`book-cover ${artTone}`}>
                      {book.cover_image_url ? <img src={book.cover_image_url} alt={`Cover of ${book.title}`} /> : <div className="cover-art"><span>{book.category || "THE COLLECTION"}</span><b>{book.title}</b><small>{book.author}</small><i aria-hidden="true">✳</i></div>}
                      <span className={`availability ${available ? "available" : "unavailable"}`}><i />{available ? `${available} available` : physical ? "All borrowed" : "Reference title"}</span>
                    </div>
                    <div className="book-meta"><span className="book-category">{book.category}</span><h3>{book.title}</h3><p className="book-author">by {book.author}</p>{book.description && <p className="book-description">{book.description}</p>}<div className="book-card-bottom">{available === 0 && physical > 0 ? <ReservationButton bookId={book.id} /> : <span className="availability-note">{available > 0 ? "Available to borrow at the library" : "Ask the library team about this title"}</span>}<span className="book-shelf">{book.shelf_code || "LIBRARY"}</span></div></div>
                  </article>
                );
              })}
            </div>
          ) : <div className="empty-state"><span>⌕</span><h3>No matching titles</h3><p>Try a different search or category.</p></div>}
        </section>

        <section className="member-lower-grid">
          <section className="content-section compact-section" id="my-loans">
            <div className="section-heading small-heading"><div><p className="section-kicker">YOUR READING JOURNEY</p><h2>Books in your care</h2></div><span className="count-badge">{loans.length} total</span></div>
            {loans.length ? <div className="record-list">{loans.slice(0, 5).map((loan) => {
              const book = loan.book_copies?.books;
              return <article className="record-row" key={loan.id}><span className="record-icon">▤</span><div className="record-main"><strong>{book?.title ?? "Library book"}</strong><small>{book?.author ?? loan.book_copies?.barcode ?? "Book copy"}</small></div><div className="record-due"><small>Due {dateLabel(loan.due_at)}</small><span className={`status-pill ${loan.status.toLowerCase()}`}>{loan.status}</span></div></article>;
            })}</div> : <div className="empty-inline"><span>✧</span><p>No borrowed books just yet. Your next read is waiting in the catalogue.</p></div>}
          </section>
          <section className="content-section compact-section" id="reservations">
            <div className="section-heading small-heading"><div><p className="section-kicker">IN THE QUEUE</p><h2>Your reservations</h2></div><span className="count-badge">{activeReservations.length} active</span></div>
            {activeReservations.length ? <div className="record-list">{activeReservations.slice(0, 5).map((reservation) => <article className="record-row" key={reservation.id}><span className="record-icon reservation-icon">⌑</span><div className="record-main"><strong>{reservation.books?.title ?? "Library title"}</strong><small>{reservation.books?.author ?? "Reservation request"}</small></div><div className="record-due"><span className={`status-pill ${reservation.status.toLowerCase()}`}>{reservation.status}</span>{reservation.status === "Pending" && <CancelReservationButton reservationId={reservation.id} />}</div></article>)}</div> : <div className="empty-inline"><span>✧</span><p>No active reservations. When a title is all checked out, you can request a hold.</p></div>}
          </section>
        </section>

        <footer className="workspace-footer"><span>© {new Date().getFullYear()} SmartLib Pro</span><span>Made for readers, wherever their next chapter begins.</span><a href="mailto:library@example.com">Need support? <b>Contact your librarian →</b></a></footer>
      </main>
    </div>
  );
}
