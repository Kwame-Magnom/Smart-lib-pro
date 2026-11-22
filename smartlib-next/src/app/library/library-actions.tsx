"use client";

import { useActionState } from "react";
import {
  cancelReservation,
  requestReservation,
  type FormState,
} from "./actions";

const initialState: FormState = { message: "", tone: "success" };

export function ReservationButton({ bookId }: { bookId: number }) {
  const [state, action, pending] = useActionState(requestReservation, initialState);

  return (
    <div className="inline-action">
      <form action={action}>
        <input type="hidden" name="book_id" value={bookId} />
        <button className="button button-small button-outline" disabled={pending}>
          {pending ? "Requesting…" : "Request a hold"}
        </button>
      </form>
      {state.message && <span className={`inline-message ${state.tone}`} role="status">{state.message}</span>}
    </div>
  );
}

export function CancelReservationButton({ reservationId }: { reservationId: number }) {
  const [state, action, pending] = useActionState(cancelReservation, initialState);

  return (
    <div className="inline-action">
      <form action={action}>
        <input type="hidden" name="reservation_id" value={reservationId} />
        <button className="quiet-button" disabled={pending}>
          {pending ? "Cancelling…" : "Cancel request"}
        </button>
      </form>
      {state.message && <span className={`inline-message ${state.tone}`} role="status">{state.message}</span>}
    </div>
  );
}
