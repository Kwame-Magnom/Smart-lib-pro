"use client";

import Link from "next/link";
import { useActionState } from "react";
import { signOut, type FormState } from "../library/actions";
import { createBook, reviewCourseRequest } from "./actions";

type RequestRecord = {
  id: number;
  request_note: string | null;
  requested_at: string;
  requester: { display_name: string; email: string } | null;
  course: { name: string } | null;
  book: { title: string; author: string } | null;
};

const initialState: FormState = { message: "", tone: "success" };

function AddBookForm() {
  const [state, action, pending] = useActionState(createBook, initialState);
  return (
    <form className="admin-book-form" action={action}>
      <label>Title<input name="title" required maxLength={180} placeholder="Book title" /></label>
      <label>Author<input name="author" required maxLength={140} placeholder="Author name" /></label>
      <label>Category<input name="category" maxLength={80} placeholder="e.g. Literature" /></label>
      <label>ISBN<input name="isbn" maxLength={32} placeholder="Optional" /></label>
      <label>Shelf location<input name="shelf_code" maxLength={50} placeholder="e.g. A-12" /></label>
      <label>Physical copies<input name="copy_count" type="number" min={0} max={500} defaultValue={1} required /></label>
      <label className="wide-field">Description<textarea name="description" rows={3} maxLength={5000} placeholder="A short description to help readers discover this title" /></label>
      <div className="admin-form-footer"><button className="button button-primary" disabled={pending}>{pending ? "Adding title…" : "Add to catalogue"} <span aria-hidden="true">→</span></button>{state.message && <span className={`inline-message ${state.tone}`} role="status">{state.message}</span>}</div>
    </form>
  );
}

function ReviewForm({ requestId }: { requestId: number }) {
  const [state, action, pending] = useActionState(reviewCourseRequest, initialState);
  return (
    <form className="review-form" action={action}>
      <input type="hidden" name="request_id" value={requestId} />
      <input name="review_note" maxLength={300} placeholder="Optional review note" aria-label="Optional review note" />
      <div>
        <button name="decision" value="Approved" disabled={pending}>Approve</button>
        <button className="reject-button" name="decision" value="Rejected" disabled={pending}>Reject</button>
      </div>
      {state.message && <span className={`inline-message ${state.tone}`} role="status">{state.message}</span>}
    </form>
  );
}

export default function AdminWorkspace({
  name,
  role,
  stats,
  requests,
}: {
  name: string;
  role: string;
  stats: { members: number; titles: number; loans: number; requests: number };
  requests: RequestRecord[];
}) {
  const initials = name.split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase();
  return (
    <div className="app-shell">
      <aside className="app-sidebar">
        <Link className="brand sidebar-brand" href="/"><span className="brand-icon" aria-hidden="true">S</span><span>SmartLib <b>Pro</b></span></Link>
        <p className="side-label">ADMINISTRATION</p>
        <nav className="side-nav" aria-label="Administrator navigation">
          <a className="side-link active" href="#admin-overview"><span>◈</span> Overview</a>
          <a className="side-link" href="#add-title"><span>＋</span> Add a title</a>
          <a className="side-link" href="#requests"><span>⌑</span> Course requests <small>{stats.requests}</small></a>
          <Link className="side-link" href="/admin/security"><span>✦</span> Security &amp; backups</Link>
          <Link className="side-link" href="/library"><span>▤</span> Member catalogue</Link>
        </nav>
        <div className="sidebar-bottom">
          <div className="sidebar-help"><span>✦</span><b>Administration</b><p>Review access and activity with care. Every role is enforced by database policies.</p></div>
          <form action={signOut}><button className="sidebar-signout" type="submit"><span>↪</span> Sign out</button></form>
        </div>
      </aside>
      <main className="library-main" id="admin-overview">
        <header className="workspace-topbar">
          <div className="breadcrumb"><span>SmartLib</span><b>/</b> Staff workspace</div>
          <div className="profile-menu"><span className="user-avatar">{initials || "A"}</span><div><strong>{name}</strong><small>{role}</small></div></div>
        </header>
        <section className="admin-welcome">
          <div><p className="hero-kicker"><span /> LIBRARY OPERATIONS</p><h1>Good work starts with a clear view.</h1><p>Manage your collection and keep member requests moving.</p></div>
          <span className="admin-welcome-mark" aria-hidden="true">✳</span>
        </section>
        <section className="admin-stat-grid" aria-label="Library overview">
          <article><span>ACTIVE MEMBERS</span><strong>{stats.members.toLocaleString()}</strong><small>School community</small></article>
          <article><span>ACTIVE TITLES</span><strong>{stats.titles.toLocaleString()}</strong><small>In the catalogue</small></article>
          <article><span>ACTIVE LOANS</span><strong>{stats.loans.toLocaleString()}</strong><small>Currently checked out</small></article>
          <article><span>WAITING FOR REVIEW</span><strong>{stats.requests.toLocaleString()}</strong><small>Course book requests</small></article>
        </section>
        <div className="admin-columns">
          <section className="admin-panel" id="add-title">
            <div className="section-heading admin-heading"><div><p className="section-kicker">GROW THE COLLECTION</p><h2>Add a catalogue title</h2><p>New copies receive individual barcodes automatically.</p></div><span className="admin-panel-icon">＋</span></div>
            <AddBookForm />
          </section>
          <section className="admin-panel" id="requests">
            <div className="section-heading admin-heading"><div><p className="section-kicker">MEMBER LEARNING</p><h2>Course book requests</h2><p>Approve requests for members to visit the library desk.</p></div><span className="count-badge">{requests.length} pending</span></div>
            {requests.length ? <div className="request-list">{requests.map((request) => <article className="request-item" key={request.id}><div className="request-book"><span className="record-icon reservation-icon">⌑</span><div><strong>{request.book?.title ?? "Course title"}</strong><small>{request.book?.author ?? ""} · {request.course?.name ?? "Course"}</small></div></div><p>Requested by <b>{request.requester?.display_name || request.requester?.email || "Member"}</b>{request.request_note ? ` — “${request.request_note}”` : ""}</p><ReviewForm requestId={request.id} /></article>)}</div> : <div className="empty-inline"><span>✧</span><p>All clear. There are no course book requests waiting for review.</p></div>}
          </section>
        </div>
        <footer className="workspace-footer"><span>© {new Date().getFullYear()} SmartLib Pro</span><span>Keep the shelves inspiring.</span><Link href="/library">Return to member space →</Link></footer>
      </main>
    </div>
  );
}
