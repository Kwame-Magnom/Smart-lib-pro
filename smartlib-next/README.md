# SmartLib Pro — Next.js + Supabase

This is the Vercel-ready rewrite of the PHP/MySQL library application. The original PHP app remains in the repository and is not modified by this rewrite.

## What is implemented

- Password sign-in followed by a required TOTP authenticator challenge through Supabase Auth MFA.
- New accounts are always created as standard `User` members.
- A profile is automatically created when Supabase Auth creates an account.
- Role-aware, row-level-secured library data in Supabase Postgres.
- Member catalogue browsing, personal loans and reservations.
- Reservation requests and cancellations are enforced by database policies.
- Protected pages and database tables require an `aal2` session; users must verify their authenticator factor before accessing library data.
- Responsive library dashboard with live Supabase data.

The database migration also establishes the foundations for staff workflows, courses, course-book requests, fines, payments, and notifications. The PHP app's complete administrator/librarian functionality has not yet been ported; use the original PHP version for those workflows until their Next.js pages are implemented.

## Local setup

1. Create a Supabase project.
2. In **Project Settings → API**, copy the project URL and publishable/anon key.
3. Copy `.env.example` to `.env.local` and fill in those two public values. Never put a Supabase `service_role` key in a `NEXT_PUBLIC_*` variable or browser code.
4. In the Supabase SQL Editor, run both migrations in order: `supabase/migrations/0001_library.sql`, then `supabase/migrations/0002_require_totp_mfa.sql`.
5. In **Authentication → Providers**, enable email/password sign-in and email confirmation. Set the minimum password length to at least 12 characters.
6. In **Authentication → Settings / Multi-Factor Authentication**, enable the TOTP authenticator factor and keep verification enabled. The app's first sign-in enrolls an authenticator; later sign-ins require a fresh six-digit code.
7. In **Authentication → URL Configuration**, add the local URL `http://localhost:3000` to the allowed redirect URLs.
8. Run:

   ```powershell
   npm install
   npm run dev
   ```

9. Open `http://localhost:3000`.

The application uses password plus TOTP MFA. Existing PHP password hashes cannot be imported into Supabase Auth as working accounts. Register with a new email, or plan a separate account migration. Authenticator setup secrets are shown only during enrollment; users should save the QR code in their authenticator app and retain a secure recovery path.

## Password hashing and salt/pepper

Supabase Auth stores password hashes using bcrypt with a unique random salt per password. SmartLib does not receive or store raw passwords, and does not add a custom application pepper to Supabase-managed credentials. This differs from the legacy PHP backend, which uses PHP password hashing plus a server-side `APP_PEPPER`. Never expose the legacy pepper or a Supabase service-role key in browser code.

## Automatic database backups

Backup schedules are controlled by the Supabase project, not by Vercel or the browser application. Pro projects receive daily backups with 7 days of retention, Team projects with 14 days, and Enterprise projects with up to 30 days. Free projects should schedule regular Supabase CLI exports to private off-site storage. Point-in-Time Recovery is available as a paid add-on and replaces daily backups while enabled. Database backups do not include files in Supabase Storage.

Before production use, open **Supabase Dashboard → Database → Backups**, confirm the project's plan and backup/retention policy, and test a restore in a separate project. The administrator **Security & backups** page links to the provider guide; it deliberately does not claim that a backup is configured or expose management credentials.

## Granting staff roles

Public registration cannot choose a role. To grant a role to a trusted account, first have that person register and verify their email, then use the Supabase SQL Editor as the project owner:

```sql
update public.profiles
set role = 'Administrator'
where email = 'trusted-admin@example.com';
```

Replace the role/email intentionally. Never expose a role selector in public signup.

## Deploy to Vercel

1. Push the repository to GitHub.
2. In Vercel, import the repository and set the project **Root Directory** to `smartlib-next`.
3. Add `NEXT_PUBLIC_SUPABASE_URL` and `NEXT_PUBLIC_SUPABASE_ANON_KEY` as Vercel environment variables for Preview and Production.
4. Deploy, then add the resulting `https://...vercel.app` URL to Supabase **Authentication → URL Configuration** (Site URL and allowed redirect URLs).
5. Test registration, password sign-in, first-time TOTP enrollment, TOTP verification on later sign-ins, catalogue browsing, and reservation access on the deployed URL.

## Security notes

- Supabase Row Level Security is enabled for every application table in the migration.
- Browser code uses only the Supabase publishable/anon key; database access is constrained by RLS.
- User roles are not accepted from signup metadata. Role changes are an explicit trusted-admin operation.
- Keep `.env.local`, database exports, and any service-role key out of GitHub.
