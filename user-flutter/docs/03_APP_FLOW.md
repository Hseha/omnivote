# 03 — App Flow

## Navigation shell

The reference UI uses a permanent left sidebar (desktop web). On Flutter/mobile this becomes:

- **Bottom navigation bar** with 5 primary destinations: `Dashboard`, `Vote Now`, `Candidates`, `My Ballot`, `Election Results`
- **`Help & FAQ`** and **`My Profile`** move into an overflow / drawer or the top-right avatar menu (they're low-frequency)
- **Top bar** persists on every screen: breadcrumb-style page title, `Voting Open` status badge + live clock, avatar (name + grade/year)

## Screen-to-screen flow [Status: Initial Connection Implemented]

```mermaid
flowchart TD
    A[Splash / Auth check] -->|not logged in| B[Login Screen]
    A -->|logged in| C[Dashboard]
    B -->|SUBMIT| C [DONE: Connected via GoRouter]

    A -- DONE: Initial location in AppRouter --> B
    C -->|bottom nav| D[Candidates List]
    C -->|bottom nav| E[Vote Now]
    C -->|bottom nav| F[My Ballot]
    C -->|bottom nav| G[Election Results]
    C -->|menu| H[Help & FAQ]
    C -->|avatar| I[My Profile]
    C -->|menu / CTA| J[Apply for Candidacy]

    D -->|tab: School tier| D1[Position tabs: President, VP, Secretary,
Treasurer, Auditor, Press Officer,
Senator x12, Year Level Rep,
Property Custodian]
    D -->|tab: Provincial tier| D2[Position tabs: Governor, Vice Governor,
Prov Secretary, Prov Treasurer,
Prov Auditor, Prov Press Officer,
Prov Custodian]
    D1 --> K[Candidate Profile]
    D2 --> K
    K -->|Vote button| E

    E --> E1[Step through each position,
one at a time, in order]
    E1 -->|select + confirm each step| E2[Review full ballot]
    E2 -->|Submit Ballot| F
    F -->|Submit / already submitted| L[Vote Receipt Token shown]

    G -->|before polls close| G1[Turnout / live status only]
    G -->|after polls close| G2[Per-position results + bar chart]
    G2 -->|enter receipt token| G3[Verify my vote was counted]
```

## Narrative walkthrough

1. **Auth check → Dashboard.** On launch, check for a stored session token. If absent/expired, show Login. On success, land on **Dashboard**, which mirrors the reference "Voter Registration" screen: a green "Registration Complete" banner, the student's registration details card, and the school-wide turnout progress bars, plus an expandable Eligibility FAQ.

2. **Candidates.** From the bottom nav, students browse candidates. This screen is **tier + position driven**, not one screen per position:
   - A top-level toggle/tab switches between **School** and **Provincial** tiers.
   - Within a tier, a second row of tabs/chips switches between positions (President, Vice President, Secretary, Treasurer, Auditor, Press Officer, Senator, Year Level Representative, Property Custodian for School; Governor, Vice Governor, Secretary, Treasurer, Auditor, Press Officer, Custodian for Provincial).
   - The Presidential tab additionally shows a search bar + "Position" / "Grade" filter dropdowns (as in the reference), since it's the highest-traffic list.
   - Senator is the one **multi-select** list (12 seats available) — everything else is single-select per position.
   - Tapping "View Profile" opens the **Candidate Profile** screen (photo, slogan, numbered Campaign Platform, Qualifications & Experience, embedded Campaign Video). Tapping "Vote" (from either the list card or the profile) registers that selection into the in-progress ballot and returns the student to where they were, with a confirmation toast — it does **not** immediately submit the whole ballot.

3. **Vote Now.** A guided, step-by-step flow through every position across both tiers in a fixed order, so a student doesn't miss a position. Each step reuses the same candidate list/selection UI as the Candidates screen. Positions can also be answered out of order by voting directly from the Candidates screen — Vote Now is the "finish what I haven't voted on yet" guided path.

4. **My Ballot.** A single scrollable summary — one row per position showing the currently selected candidate(s) — with an **Edit** action per row (jumps back to that position's candidate list) and a **Submit Ballot** action once every required position has a selection. After submission, the ballot becomes read-only and a **digital receipt token** is shown (matches the reference Dashboard FAQ: "you will receive a digital receipt token... input this anonymous token on the Results tab to verify your vote was counted").

5. **Election Results.** Before polls close: turnout/status only (no per-candidate numbers, to avoid implying live results). After polls close: per-position results with a bar chart, plus a field to paste the receipt token from step 4 to confirm the student's vote was counted — this must never reveal *who* the student voted for, only that a vote was recorded.

6. **Apply for Candidacy.** A standalone form (Full Name, Student ID, University Email, Course/Program, Year Level, Position Running For, Party/Platform Name, Campaign Platform/Statement, Official Candidate Photo upload, certification checkbox). Submission goes to a pending-review state; the student does **not** immediately appear on the Candidates list — that only happens once the (separate, React-side) Election Committee approves it. Reachable from the Dashboard/menu, not the bottom nav, since it's used rarely.

7. **Help & FAQ.** Static/CMS-driven support content, reachable from the overflow menu.

## Voting window states to design for

Every screen that shows candidates or a ballot must handle three global states, driven by the API's election status field:

- `not_registered` — student can view candidates but voting actions are disabled with an explanatory banner
- `voting_open` — full flow as described above
- `voting_closed` — Candidates/Profile become read-only (no Vote buttons), Vote Now redirects to My Ballot (read-only) or Results
