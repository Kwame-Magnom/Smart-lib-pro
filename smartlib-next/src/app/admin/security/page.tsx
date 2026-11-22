import Link from "next/link";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";

const adminRoles = ["Super Administrator", "Administrator"];

export default async function AdminSecurityPage() {
  const supabase = await createClient();
  const { data: authData, error: authError } = await supabase.auth.getUser();
  if (authError || !authData.user) redirect("/login");

  const { data: profile, error: profileError } = await supabase
    .from("profiles")
    .select("role, account_status")
    .eq("id", authData.user.id)
    .maybeSingle();
  if (profileError) throw new Error(`Could not check your permissions: ${profileError.message}`);
  if (!profile || profile.account_status !== "Active" || !adminRoles.includes(profile.role)) {
    redirect("/library");
  }

  return (
    <main className="security-info-page">
      <p className="section-kicker">ADMINISTRATION · SECURITY</p>
      <h1>Account security &amp; backups</h1>
      <p className="security-intro">
        SmartLib requires a password and a verified authenticator-app code before
        application data is available.
      </p>
      <div className="security-info-grid">
        <section className="security-info-card">
          <h2>Two-factor authentication</h2>
          <p>
            Supabase Auth issues an AAL1 session after password sign-in. SmartLib
            requires AAL2, established by verifying a TOTP authenticator factor,
            before protected pages, server actions, or database rows are available.
          </p>
          <p>New members enroll an authenticator during their first sign-in.</p>
          <a href="https://supabase.com/docs/guides/auth/auth-mfa/totp" target="_blank" rel="noreferrer">
            Authenticator setup and recovery guide
          </a>
        </section>
        <section className="security-info-card">
          <h2>Salt &amp; pepper</h2>
          <ul>
            <li><strong>Salt:</strong> Supabase Auth stores password hashes using bcrypt with a unique random salt per password.</li>
            <li><strong>Pepper:</strong> SmartLib does not add a custom pepper to Supabase-managed passwords; the password is sent to Supabase Auth and is not persisted or logged by this application.</li>
          </ul>
          <p>
            The legacy PHP app uses a separate server-side pepper. Do not copy
            that secret into browser code or a <code>NEXT_PUBLIC_*</code> variable.
          </p>
        </section>
        <section className="security-info-card">
          <h2>Automatic database backups</h2>
          <p>
            Backup status is controlled by the Supabase project and cannot be
            safely enabled or verified from this browser-only admin page.
          </p>
          <ul>
            <li>Pro projects receive daily backups with 7 days of retention.</li>
            <li>Team projects retain 14 days; Enterprise projects up to 30 days.</li>
            <li>Free projects should schedule Supabase CLI exports to private off-site storage.</li>
            <li>Point-in-Time Recovery is a paid option and replaces daily backups while enabled.</li>
            <li>Database backups do not include files stored through Supabase Storage.</li>
          </ul>
          <a href="https://supabase.com/dashboard/" target="_blank" rel="noreferrer">Open Supabase Dashboard</a>
        </section>
        <section className="security-info-card">
          <h2>Restore readiness</h2>
          <p>
            A backup is only useful if it can be restored. Schedule periodic
            restore tests in a separate project and document who can approve a
            production restore.
          </p>
          <a href="https://supabase.com/docs/guides/platform/backups" target="_blank" rel="noreferrer">Supabase backup and restore guide</a>
        </section>
      </div>
      <p className="security-info-notice">
        This page explains the configured security model; it does not report a
        verified backup job or restore point. Confirm backup availability and
        retention in the Supabase Dashboard before storing production data.
      </p>
      <Link className="security-info-back" href="/admin">← Back to administration</Link>
    </main>
  );
}
