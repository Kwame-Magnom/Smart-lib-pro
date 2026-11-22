import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import AdminWorkspace from "./admin-workspace";

const adminRoles = ["Super Administrator", "Administrator"];

export default async function AdminPage() {
  const supabase = await createClient();
  const { data: authData, error: authError } = await supabase.auth.getUser();
  if (authError || !authData.user) redirect("/login");

  const { data: profile, error: profileError } = await supabase
    .from("profiles")
    .select("id, email, display_name, role, account_status")
    .eq("id", authData.user.id)
    .maybeSingle();
  if (profileError) throw new Error(`Could not check your permissions: ${profileError.message}`);
  if (!profile || profile.account_status !== "Active" || !adminRoles.includes(profile.role)) {
    redirect("/library");
  }

  const [membersResult, titlesResult, loansResult, requestsResult, pendingResult] = await Promise.all([
    supabase.from("profiles").select("id", { count: "exact", head: true }).in("role", ["User", "Student", "Teacher", "Pastor"]).eq("account_status", "Active"),
    supabase.from("books").select("id", { count: "exact", head: true }).eq("book_status", "Active"),
    supabase.from("loans").select("id", { count: "exact", head: true }).in("status", ["Active", "Overdue"]),
    supabase.from("course_book_requests").select("id", { count: "exact", head: true }).eq("status", "Pending"),
    supabase.from("course_book_requests")
      .select("id, request_note, requested_at, requester:profiles!course_book_requests_requested_by_fkey(display_name,email), course_book:course_books!course_book_requests_course_book_id_fkey(course:courses(name), book:books(title,author))")
      .eq("status", "Pending")
      .order("requested_at", { ascending: true })
      .limit(30),
  ]);
  if (membersResult.error) throw new Error(`Could not count active members: ${membersResult.error.message}`);
  if (titlesResult.error) throw new Error(`Could not count catalogue titles: ${titlesResult.error.message}`);
  if (loansResult.error) throw new Error(`Could not count active loans: ${loansResult.error.message}`);
  if (requestsResult.error) throw new Error(`Could not count pending requests: ${requestsResult.error.message}`);
  if (pendingResult.error) throw new Error(`Could not load course requests: ${pendingResult.error.message}`);

  const requests = (pendingResult.data ?? []).map((row) => {
    const relations = row.course_book as unknown as {
      course: { name: string } | null;
      book: { title: string; author: string } | null;
    } | null;
    return {
      id: row.id,
      request_note: row.request_note,
      requested_at: row.requested_at,
      requester: row.requester as unknown as { display_name: string; email: string } | null,
      course: relations?.course ?? null,
      book: relations?.book ?? null,
    };
  });

  return (
    <AdminWorkspace
      name={profile.display_name.trim() || profile.email}
      role={profile.role}
      stats={{
        members: membersResult.count ?? 0,
        titles: titlesResult.count ?? 0,
        loans: loansResult.count ?? 0,
        requests: requestsResult.count ?? 0,
      }}
      requests={requests}
    />
  );
}
