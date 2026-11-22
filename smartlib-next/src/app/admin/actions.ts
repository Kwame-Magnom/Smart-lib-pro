"use server";

import { revalidatePath } from "next/cache";
import { createClient } from "@/lib/supabase/server";
import type { FormState } from "../library/actions";

const adminRoles = ["Super Administrator", "Administrator"];

async function requireAdministrator() {
  const supabase = await createClient();
  const { data: authData, error: authError } = await supabase.auth.getUser();
  if (authError || !authData.user) {
    throw new Error("Sign in with an administrator account to continue.");
  }

  const { data: profile, error } = await supabase
    .from("profiles")
    .select("role, account_status")
    .eq("id", authData.user.id)
    .single();
  if (error || !profile || profile.account_status !== "Active" || !adminRoles.includes(profile.role)) {
    throw new Error("This action requires an active administrator account.");
  }

  return { supabase, userId: authData.user.id };
}

export async function createBook(
  _previousState: FormState,
  formData: FormData,
): Promise<FormState> {
  const title = String(formData.get("title") ?? "").trim();
  const author = String(formData.get("author") ?? "").trim();
  const category = String(formData.get("category") ?? "").trim();
  const description = String(formData.get("description") ?? "").trim();
  const isbn = String(formData.get("isbn") ?? "").trim();
  const shelfCode = String(formData.get("shelf_code") ?? "").trim();
  const copyCount = Number(formData.get("copy_count"));

  if (!title || title.length > 180 || !author || author.length > 140 ||
      category.length > 80 || description.length > 5000 || isbn.length > 32 ||
      shelfCode.length > 50 || !Number.isInteger(copyCount) || copyCount < 0 || copyCount > 500) {
    return { message: "Check the required fields and copy count (0–500).", tone: "error" };
  }

  try {
    const { supabase } = await requireAdministrator();
    const { error } = await supabase.rpc("create_book_with_copies", {
      p_title: title,
      p_author: author,
      p_category: category || "General",
      p_description: description,
      p_isbn: isbn,
      p_shelf_code: shelfCode,
      p_copy_count: copyCount,
    });
    if (error) {
      return { message: error.message, tone: "error" };
    }

    revalidatePath("/admin");
    revalidatePath("/library");
    return { message: `“${title}” was added to the catalogue.`, tone: "success" };
  } catch (caught) {
    return { message: caught instanceof Error ? caught.message : "The title could not be added.", tone: "error" };
  }
}

export async function reviewCourseRequest(
  _previousState: FormState,
  formData: FormData,
): Promise<FormState> {
  const requestId = Number(formData.get("request_id"));
  const decision = String(formData.get("decision") ?? "");
  const note = String(formData.get("review_note") ?? "").trim();

  if (!Number.isSafeInteger(requestId) || requestId < 1 ||
      !["Approved", "Rejected"].includes(decision) || note.length > 300) {
    return { message: "Choose an approval decision and keep the note under 300 characters.", tone: "error" };
  }

  try {
    const { supabase, userId } = await requireAdministrator();
    const { data, error } = await supabase
      .from("course_book_requests")
      .update({
        status: decision,
        reviewed_by: userId,
        review_note: note || null,
        reviewed_at: new Date().toISOString(),
      })
      .eq("id", requestId)
      .eq("status", "Pending")
      .select("id")
      .maybeSingle();

    if (error) {
      return { message: error.message, tone: "error" };
    }
    if (!data) {
      return { message: "This request was already reviewed or is no longer available.", tone: "error" };
    }

    revalidatePath("/admin");
    return { message: `Request ${decision.toLowerCase()}.`, tone: "success" };
  } catch (caught) {
    return { message: caught instanceof Error ? caught.message : "The request could not be reviewed.", tone: "error" };
  }
}
