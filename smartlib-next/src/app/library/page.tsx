import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import LibraryWorkspace, {
  type BookRecord,
  type LoanRecord,
  type ProfileRecord,
  type ReservationRecord,
} from "./workspace";

export default async function LibraryPage() {
  const supabase = await createClient();
  const { data: authData, error: authError } = await supabase.auth.getUser();
  if (authError || !authData.user) {
    redirect("/login");
  }

  const [
    profileResult,
    booksResult,
    loansResult,
    reservationsResult,
  ] = await Promise.all([
    supabase
      .from("profiles")
      .select("id, email, display_name, school_id, role, account_status")
      .eq("id", authData.user.id)
      .maybeSingle(),
    supabase
      .from("books")
      .select("id, title, author, isbn, category, description, cover_image_url, shelf_code, book_copies(status)")
      .eq("book_status", "Active")
      .order("title")
      .limit(100),
    supabase
      .from("loans")
      .select("id, issued_at, due_at, status, book_copies(barcode, books(title, author))")
      .order("issued_at", { ascending: false })
      .limit(100),
    supabase
      .from("reservations")
      .select("id, reserved_at, status, books(title, author)")
      .order("reserved_at", { ascending: false })
      .limit(100),
  ]);

  if (profileResult.error) throw new Error(`Could not load your profile: ${profileResult.error.message}`);
  if (booksResult.error) throw new Error(`Could not load the catalogue: ${booksResult.error.message}`);
  if (loansResult.error) throw new Error(`Could not load your loans: ${loansResult.error.message}`);
  if (reservationsResult.error) throw new Error(`Could not load your reservations: ${reservationsResult.error.message}`);
  if (!profileResult.data || profileResult.data.account_status !== "Active") {
    await supabase.auth.signOut();
    redirect("/login");
  }

  return (
    <LibraryWorkspace
      profile={profileResult.data as ProfileRecord}
      books={(booksResult.data ?? []) as BookRecord[]}
      loans={(loansResult.data ?? []) as LoanRecord[]}
      reservations={(reservationsResult.data ?? []) as ReservationRecord[]}
    />
  );
}
