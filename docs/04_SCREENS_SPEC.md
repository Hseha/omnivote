# 04 — Screens Spec

Derived directly from the reference "OmniVote Student Portal" web screenshots. Each section lists: purpose, data needed, key components, and mobile adaptation notes.

---

## Global positions list (used everywhere below)

**School tier**
`president`, `vicePresident`, `secretary`, `treasurer`, `auditor`, `pressOfficer`, `senator` (12 seats, multi-select), `yearLevelRep` (1 per grade level: 10/11/12), `propertyCustodian`

**Provincial tier**
`governor`, `viceGovernor`, `provincialSecretary`, `provincialTreasurer`, `provincialAuditor`, `provincialPressOfficer`, `provincialCustodian`

Every position has: `id`, `label` (e.g. "Presidential Candidate", "Prov Press Officer"), `tier` (`school`/`provincial`), `seatCount` (1 unless senator = 12), `description` (the one-line subheading shown per list, e.g. "Vote for a press officer to amplify voices across the province.").

---

## 1. Login Screen
- Fields: school email / student ID, password. Submit → `POST /auth/login`.
- On success, store token via `secure_storage_service` and route to Dashboard.

## 2. Dashboard (maps to reference "Voter Registration" screen)
- **Registration Complete banner**: green check, "You registered on {date}. Your account is active and eligible to cast a ballot in all ongoing student body elections."
- **My Registration Details card**: Student ID, Full Name, Grade, Homeroom, Registration Date, Eligibility Status (`Eligible Voter` in green).
- **Turnout card**: two progress bars — "Registered vs Total Students" and "Actual Ballots Cast To Date" — each with a fraction + percentage label.
- **Eligibility FAQ**: expandable Q&A list (requirements to vote, missed registration window, how to verify a vote was counted).
- Mobile note: stack the two cards vertically; FAQ becomes an `ExpansionTile` list.

## 3. Candidates List (one parameterized screen for all positions)
- Top: tier toggle (**School** / **Provincial**) as a `SegmentedButton`.
- Below that: horizontally scrollable position chips/tabs for the tier's positions.
- Page heading + one-line description text, taken from the position's `description` field (e.g. "12 seats available — select senators to represent the student body in legislative matters." for Senator).
- For the **President** position only: a search field ("Search candidates by name or slogan...") + two filter dropdowns (`Position`, `Grade`) above the list — reuse this filter bar for any position if product later wants it, but it's optional elsewhere.
- List renders as a **single-column stack of `candidate_card`** on mobile (reference shows a 2-column grid on desktop — do not force 2 columns on phone widths).
- **`candidate_card` contents**, top to bottom:
  - Circular/rounded photo thumbnail
  - Position tag (small blue pill, uppercase, e.g. `PROV PRESS OFFICER`)
  - Name (bold)
  - Grade line (e.g. "Grade 11 Student")
  - Italic slogan/quote
  - "KEY PLATFORM POINTS" label + bullet list (2–3 items)
  - Divider
  - Row: "View Profile" text link (left) + "Vote" filled button (right)
- **Senator list** is the one case where `candidate_card` shows a checkbox/selectable state instead of an immediate single Vote action, and a running counter "X / 12 selected" pinned above or below the list.

## 4. Candidate Profile
- Hero area: large photo, position tag + grade line, name (large bold), italic slogan, two buttons: **Vote for {FirstName}** (primary) and **Back to Candidates** (secondary/outline).
- **Campaign Platform** card: numbered list (1, 2, 3…) each with a bold sub-heading and one sentence of detail (e.g. "1. Unified Forums — Establish a monthly town hall where students can address the administration directly.").
- **Qualifications & Experience** card: simple bullet list of prior roles/achievements.
- **Campaign Video** card: thumbnail with centered play button + one-line caption ("Listen to {Name}'s 2-minute pitch to voters"). Use a video player package; do not autoplay.
- Mobile note: stack all cards vertically in this order: hero → Campaign Platform → Campaign Video → Qualifications & Experience (video benefits from being seen before the long qualifications list on a small screen).

## 5. Vote Now (guided ballot flow)
- Stepper UI: progress indicator "Step X of N" where N = total positions across both tiers.
- Each step reuses the Candidates List filtered to a single position, plus a "Skip for now" and "Confirm & Next" action.
- On the Senator step, "Confirm & Next" is disabled until between 1 and 12 candidates are selected (product may require exactly up to 12, but allow fewer — do not force students to fill all 12 seats).
- Last step routes to **My Ballot** for final review.

## 6. My Ballot
- One row per position: position label, selected candidate name(s) (or "Not yet selected" in muted/warning style), and an "Edit" text link that jumps back into that position's candidate list.
- Sticky bottom bar: **Submit Ballot** button, disabled until all required single-seat positions have a selection (senator can have 1–12).
- After submission: rows become read-only, and a **Vote Receipt Token** card appears (large monospace token + "Save this — you'll need it to verify your vote on Election Results" + copy-to-clipboard action).

## 7. Election Results
- Before polls close: a status message ("Results will be available once polls close on {date}.") plus the same turnout numbers as Dashboard — never show partial per-candidate tallies mid-election.
- After polls close: per-position sections, each with a horizontal bar chart of candidates ranked by vote count, and a small "Winner" badge on the top result.
- **Verify my vote** card: text field for the receipt token from My Ballot + "Verify" button → shows a simple "✓ Your vote was counted" or "Token not found" response. Never display which candidates the token voted for.

## 8. Apply for Candidacy (maps directly to reference screenshot)
- Info banner: "File Your Candidacy Application — You are applying as an official candidate... your profile will be audited by the Election Committee."
- **Candidate Information Form**, in this exact field order:
  1. Full Name * (prefilled, read-only from student profile)
  2. Student ID * (prefilled, read-only)
  3. University Email Address * (prefilled, editable)
  4. Course / Program * (dropdown, prefilled)
  5. Year Level * (dropdown, prefilled)
  6. Position Running For * (dropdown — full positions list from above, filtered to positions the student is eligible for by grade if the API says so)
  7. Party / Platform Name * (text)
  8. Campaign Platform / Statement * (multiline text)
  9. Official Candidate Photo * (upload field: PNG/JPG/JPEG up to 5MB, recommended square ratio)
  10. Certification checkbox * ("I certify that all information provided is true and accurate, and I agree to the election guidelines and code of conduct set by the Election Committee.")
- Actions: **Submit Application** (primary, disabled until required fields + checkbox are valid) and **Cancel** (secondary).
- Helper text under actions: "Applications are reviewed by the Election Committee within 2-3 business days. You will receive an email update once processed."

## 9. Help & FAQ
- Simple searchable FAQ list + a "Contact the Election Committee" action (mailto or in-app form, per whatever the API exposes).

## 10. My Profile (behind the top-right avatar)
- Read-only: name, grade/year level, course/program, student ID, avatar. Includes **Sign Out**.

---

## Cross-cutting UI elements (build once in `core/widgets/`)

| Element | Reference detail |
|---|---|
| `status_badge.dart` | Green dot + "Voting Open" text, shown in the top bar on every screen |
| `top_bar.dart` | Page breadcrumb ("Student Portal / Candidates"), status badge, live clock (HH:MM:SS + timezone), avatar + name + grade |
| `candidate_card.dart` | See section 3 above — used on every Candidates list and reused (styled as read-only) inside My Ballot summaries |
| `platform_points_list.dart` | The "KEY PLATFORM POINTS" bulleted block, shared by card and profile |
| `empty_state.dart` | "No candidates found" for search/filter with no matches |
