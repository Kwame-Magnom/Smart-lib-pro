"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";

export type FormState = { message: string; tone: "success" | "error" };

export async function requestReservation(
  _previousState: FormState,
  formData: FormData,
): Promise<FormState> {
  const bookId = Number(formData.get("book_id"));
  if (!Number.isSafeInteger(bookId) || bookId < 1) {
    return { message: "Choose a valid title to reserve.", tone: "error" };
  }

  const supabase = await createClient();
  const { data: userData, error: authError } = await supabase.auth.getUser();
  if (authError || !userData.user) {
    return { message: "Your session has ended. Sign in again.", tone: "error" };
  }

  const { error } = await supabase.rpc("request_reservation", { p_book_id: bookId });
  if (error) {
    return { message: error.message, tone: "error" };
  }

  revalidatePath("/library");
  return { message: "Your reservation request is in the queue.", tone: "success" };
}

export async function cancelReservation(
  _previousState: FormState,
  formData: FormData,
): Promise<FormState> {
  const reservationId = Number(formData.get("reservation_id"));
  if (!Number.isSafeInteger(reservationId) || reservationId < 1) {
    return { message: "Choose a valid reservation.", tone: "error" };
  }

  const supabase = await createClient();
  const { data: userData, error: authError } = await supabase.auth.getUser();
  if (authError || !userData.user) {
    return { message: "Your session has ended. Sign in again.", tone: "error" };
  }

  const { data, error } = await supabase.rpc("cancel_reservation", {
    p_reservation_id: reservationId,
  });
  if (error) {
    return { message: error.message, tone: "error" };
  }
  if (!data) {
    return { message: "Only a pending reservation can be cancelled.", tone: "error" };
  }

  revalidatePath("/library");
  return { message: "Reservation cancelled.", tone: "success" };
}

export async function signOut() {
  const supabase = await createClient();
  const { error } = await supabase.auth.signOut();
  if (error) {
    throw new Error(`Could not sign out: ${error.message}`);
  }
  redirect("/login");
}
