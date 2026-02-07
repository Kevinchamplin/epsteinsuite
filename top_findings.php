<?php
require_once __DIR__ . '/includes/db.php';

// Fetch top findings
try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT id, title, description, ai_summary, relevance_score, source_url, created_at, file_type
        FROM documents
        WHERE relevance_score >= 8
        ORDER BY relevance_score DESC, created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $topDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $topDocs = [];
    $error = $e->getMessage();
}

$eftaHighlights = [
    [
        'id' => 'EFTA00016732',
        'title' => 'Trump “situational awareness” memo',
        'summary' => 'SDNY prosecutors confirmed Donald Trump took at least eight Epstein flights between 1993–1996, often with Maxwell and family members aboard.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 8',
        'cta' => '/?q=EFTA00016732',
        'cta_label' => 'Search records'
    ],
    [
        'id' => 'EFTA00020517',
        'title' => 'Pilot log scans (N908JE / N909JE)',
        'summary' => 'Handwritten cockpit logs for the Boeing 727 “Lolita Express” and the Gulfstream II—distinct from typed manifests circulating online.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 9',
        'cta' => '/?q=EFTA00020517',
        'cta_label' => 'Open timeline'
    ],
    [
        'id' => 'FD-71 · Maria Farmer',
        'title' => '1996 FBI intake report',
        'summary' => 'Maria Farmer reported theft of CSAM and threats on Sept 3, 1996—proving FBI awareness a decade before Epstein’s first arrest.',
        'status' => 'mirror',
        'source' => 'justice.gov · FBI case file',
        'cta' => '/?q=FD-71%20Maria%20Farmer',
        'cta_label' => 'See complaint'
    ],
    [
        'id' => 'File 468',
        'title' => 'Removed evidence photo',
        'summary' => 'Drawer photo depicting Donald Trump, Ghislaine Maxwell, and bikini-clad women—pulled from the DOJ site less than 24 hours after release.',
        'status' => 'removed',
        'source' => 'justice.gov · Data Set 9',
        'cta' => '/efta_release.php#vanishing-files',
        'cta_label' => 'Read status note'
    ],
    [
        'id' => 'Johanna Sjoberg dep. 1320-10',
        'title' => 'Prince Andrew “Spitting Image” testimony',
        'summary' => 'May 18, 2016 deposition detailing the puppet photo with Prince Andrew and Virginia Giuffre; tie this to Giuffre v. Maxwell exhibits.',
        'status' => 'mirror',
        'source' => 'justice.gov · Civ. 1:15-cv-07433',
        'cta' => '/?q=1320-10',
        'cta_label' => 'Search transcript'
    ],
    [
        'id' => 'Larry Nassar letter',
        'title' => 'Confirmed forgery (Epstein→Nassar)',
        'summary' => 'Handwritten letter referencing Trump was flagged by DOJ as fake (wrong jail, no inmate number, dated after Epstein’s death).',
        'status' => 'forgery',
        'source' => 'justice.gov · Data Set 9',
        'cta' => '/efta_release.php#forgeries',
        'cta_label' => 'See warning'
    ],
];

$dojJan2026Highlights = [
    // — High-Profile Ties —
    [
        'id' => 'High-Profile Ties',
        'title' => 'Elon Musk: "Wildest Party" Email',
        'summary' => '2012-2013 emails show Musk asking Epstein "What day/night will be the wildest party on your island?" Epstein reportedly planned to involve Musk in his circle but failed. Musk has stated he refused invitations to the island.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Elon+Musk',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Bill Gates: Draft Emails & Affair Allegations',
        'summary' => 'Draft emails from Epstein allege Gates had extramarital affairs on the island and that a Gates Foundation employee helped him "get drugs" for "illicit trysts". A Gates spokesperson called the claims "absolutely absurd and completely false".',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Bill+Gates',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Prince Andrew: Palace Invite & "Russian" Woman',
        'summary' => 'Emails show Andrew inviting Epstein to Buckingham Palace for "privacy" in 2010, telling him "Come with whomever and I\'ll be here free from 1600ish." Epstein also offered to set the Prince up with a "26, russian, clevere beautiful" woman who already had Andrew\'s email.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Prince+Andrew',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Sarah Ferguson: Called Epstein Her "Brother"',
        'summary' => 'The former Duchess of York described Epstein as the "brother" she always wanted in an email sent a year after his conviction. She also sought his advice on how to answer questions about him before appearing on Oprah Winfrey\'s show in 2011.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Sarah+Ferguson',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Donald Trump: FBI Tips & Maxwell Allegation',
        'summary' => 'Files mention Trump hundreds of times, including unverified FBI hotline tips and a victim\'s claim that Ghislaine Maxwell "presented her" to Trump at a party. The DOJ labeled these "unverified hearsay" and "sensationalist claims" submitted shortly before the 2020 election.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Donald+Trump',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Steve Tisch: Ukrainian & Tahitian Women',
        'summary' => 'The New York Giants co-owner was in correspondence regarding women from Ukraine and Tahiti. Epstein described one woman as "Russian, and rarely tells the full truth, but fun".',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Steve+Tisch',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Howard Lutnick: Island Lunch Visit',
        'summary' => 'The current U.S. Commerce Secretary\'s wife emailed Epstein in 2012 about anchoring their boat to visit his private island for lunch.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Lutnick',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Melania Trump: "Love, Melania" Email to Maxwell',
        'summary' => 'An October 2002 email signed "Love, Melania" was sent to Ghislaine Maxwell. It remains unverified if it was authored by the current First Lady.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Melania',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Reid Hoffman: On Epstein\'s Island in 2012',
        'summary' => 'Emails suggest the LinkedIn co-founder was on Epstein\'s island in late 2012.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Reid+Hoffman',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Richard Branson: Communications with Epstein',
        'summary' => 'The billionaire communicated with Epstein, though no accusations of wrongdoing were made in the documents.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Richard+Branson',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'High-Profile Ties',
        'title' => 'Noam Chomsky & Steve Bannon: Post-Conviction Contact',
        'summary' => 'Both are listed as individuals who communicated with Epstein after his 2009 release, with Chomsky reportedly providing strategic advice.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Chomsky+Bannon',
        'cta_label' => 'Search records',
    ],
    // — Trafficking Evidence —
    [
        'id' => 'Trafficking Evidence',
        'title' => 'Harvey Weinstein: Forced Massage & Threats',
        'summary' => 'An FBI presentation describes Epstein directing a victim to give Weinstein a massage, during which he allegedly threatened to use force to make her remove her clothing. FBI documents suggest Epstein "lent out" victims to other powerful men.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Harvey+Weinstein',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Trafficking Evidence',
        'title' => 'Leon Black: Multiple Allegations',
        'summary' => 'Documents allege Epstein told a victim to give Apollo Global Management co-founder Leon Black a massage while he was naked. A second woman allegedly performed oral sex on him at Epstein\'s direction.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Leon+Black',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Trafficking Evidence',
        'title' => 'Estate Witness: Condoms, Guns & Young Girls',
        'summary' => 'A former employee told the FBI in 2007 that his duties included cleaning up used condoms after Epstein\'s massages with young girls and placing a gun between the mattresses in Epstein\'s bedroom.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=employee+condoms',
        'cta_label' => 'Search records',
    ],
    // — UK Gov & Security —
    [
        'id' => 'UK Gov & Security',
        'title' => 'Lord Mandelson: Market-Sensitive Leaks',
        'summary' => 'Emails suggest Lord Peter Mandelson sent Epstein confidential market-sensitive government information regarding the 2008 global financial crash while serving as UK Business Secretary. Mandelson subsequently resigned from the Labour Party following the release.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Mandelson',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'UK Gov & Security',
        'title' => 'Gordon Brown: Pseudonym & Secure Email Exposed',
        'summary' => 'A leaked email appeared to reveal former Prime Minister Gordon Brown\'s secure email address and his pseudonym, "John Pond".',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Gordon+Brown',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'UK Gov & Security',
        'title' => 'Princess Mette-Marit of Norway: Regret Over Contact',
        'summary' => 'The Crown Princess of Norway expressed "deep sympathy and solidarity" with victims and voiced regret over her past contact with Epstein.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Mette-Marit',
        'cta_label' => 'Search records',
    ],
    // — Institutional Failures —
    [
        'id' => 'Institutional Failures',
        'title' => '2007 Draft Federal Indictment',
        'summary' => 'Agents expected Epstein to be indicted as early as May 2007. The draft indictment would have charged Epstein and three personal assistants, adding to the controversy surrounding the non-prosecution "sweetheart deal".',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=draft+indictment',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Institutional Failures',
        'title' => 'High School Flower Delivery',
        'summary' => 'An employee note from 2007 mentions Epstein having him buy flowers and deliver them to a student at Royal Palm Beach High School to "commemorate her performance in a school play".',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Royal+Palm+Beach+High+School',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Institutional Failures',
        'title' => 'DOJ Redaction Failures',
        'summary' => 'The DOJ had to temporarily pull down thousands of documents this week because "technical or human error" left the names of some victims exposed to the public.',
        'status' => 'removed',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=redaction',
        'cta_label' => 'Search records',
    ],
    // — Clinton Connections —
    [
        'id' => 'Clinton Connections',
        'title' => 'Bill Clinton: Hot Tub & Pool Photos',
        'summary' => 'Never-before-seen photographs found in Epstein\'s New York home show Bill Clinton shirtless in a hot tub and in a pool with Ghislaine Maxwell and a third woman whose face was redacted by authorities.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Bill+Clinton',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Clinton Connections',
        'title' => 'Clinton with Michael Jackson & Diana Ross',
        'summary' => 'Images depict Clinton posing with Michael Jackson and Diana Ross at events associated with Epstein. Another photo shows the former president seated on a plane next to an unidentified woman wearing an American flag pin.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Clinton+Michael+Jackson',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Clinton Connections',
        'title' => 'Clintons Agree to Filmed Depositions',
        'summary' => 'After the House Oversight Committee voted to hold both Clintons in contempt of Congress for refusing to testify, they agreed to sit for transcribed, filmed depositions. Hillary\'s is scheduled Feb 26, Bill\'s Feb 27, 2026.',
        'status' => 'available',
        'source' => 'House Oversight Committee · Feb 2026',
        'cta' => '/?q=Clinton+deposition',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Clinton Connections',
        'title' => 'FBI Previously Investigated Clinton-Epstein Ties',
        'summary' => 'The files revealed that the FBI previously investigated allegations against Bill Clinton that were connected to the Epstein case.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Clinton+FBI',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Clinton Connections',
        'title' => '17 Flights to Siberia, Morocco, China & More',
        'summary' => 'Documents confirm Clinton took at least 17 flights on Epstein\'s private jet. Clinton\'s representatives maintain these were strictly related to Clinton Foundation work and that he broke off contact after the 2006 criminal charges.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Clinton+flights',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Clinton Connections',
        'title' => 'Epstein Visited White House 17 Times',
        'summary' => 'Visitor logs show Epstein visited the White House at least 17 times between 1993 and 1995. Former Clinton aide Mark Middleton facilitated Epstein\'s access on at least three occasions.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=White+House+Middleton',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Clinton Connections',
        'title' => 'Epstein Denied Clinton Visited His Island',
        'summary' => 'Despite ongoing rumors, emails from 2011 and 2015 included in the release show Epstein personally denying that Clinton ever visited his private island.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=Clinton+island',
        'cta_label' => 'Search records',
    ],
    // — Forensic Scale —
    [
        'id' => 'Forensic Scale',
        'title' => '10,000+ Illegal Images & Videos Seized',
        'summary' => 'The tranche contains over 10,000 downloaded videos and images of illegal child sexual abuse material and pornography seized from Epstein\'s devices.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=seized+devices',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Forensic Scale',
        'title' => 'Over 1,000 Confirmed Victims',
        'summary' => 'The DOJ review confirmed Epstein harmed over one thousand victims across the scope of his operations.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=victims',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Forensic Scale',
        'title' => 'Epstein\'s 58-Page Staff Manual',
        'summary' => 'A staff manual for Epstein\'s Florida mansion explicitly told staff they must "see nothing, hear nothing, say nothing".',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=staff+manual',
        'cta_label' => 'Search records',
    ],
    [
        'id' => 'Forensic Scale',
        'title' => 'Decoy Body: Jail Transport Deception',
        'summary' => 'Jail staff used a decoy "body" made of boxes and sheets in a white van to distract the media while Epstein\'s actual remains were removed from the jail in a black vehicle.',
        'status' => 'available',
        'source' => 'justice.gov · Jan 2026 Release',
        'cta' => '/?q=decoy',
        'cta_label' => 'Search records',
    ],
];

// Group Jan 2026 highlights by category
$jan2026Categories = [];
foreach ($dojJan2026Highlights as $item) {
    $jan2026Categories[$item['id']][] = $item;
}

$categoryMeta = [
    'High-Profile Ties' => ['icon' => 'users', 'color' => 'red'],
    'Trafficking Evidence' => ['icon' => 'alert-triangle', 'color' => 'amber'],
    'UK Gov & Security' => ['icon' => 'shield', 'color' => 'indigo'],
    'Clinton Connections' => ['icon' => 'link', 'color' => 'blue'],
    'Institutional Failures' => ['icon' => 'alert-octagon', 'color' => 'rose'],
    'Forensic Scale' => ['icon' => 'search', 'color' => 'slate'],
];

$statusBadges = [
    'available' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
    'mirror' => 'bg-slate-100 text-slate-700 border border-slate-200',
    'removed' => 'bg-amber-50 text-amber-700 border border-amber-200',
    'forgery' => 'bg-rose-50 text-rose-700 border border-rose-200',
];

$colorMap = [
    'red'     => ['bg' => 'bg-red-50',     'border' => 'border-red-200',     'text' => 'text-red-700',     'badge' => 'bg-red-100 text-red-700 border-red-200'],
    'amber'   => ['bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-700',   'badge' => 'bg-amber-100 text-amber-700 border-amber-200'],
    'indigo'  => ['bg' => 'bg-indigo-50',  'border' => 'border-indigo-200',  'text' => 'text-indigo-700',  'badge' => 'bg-indigo-100 text-indigo-700 border-indigo-200'],
    'blue'    => ['bg' => 'bg-blue-50',    'border' => 'border-blue-200',    'text' => 'text-blue-700',    'badge' => 'bg-blue-100 text-blue-700 border-blue-200'],
    'rose'    => ['bg' => 'bg-rose-50',    'border' => 'border-rose-200',    'text' => 'text-rose-700',    'badge' => 'bg-rose-100 text-rose-700 border-rose-200'],
    'slate'   => ['bg' => 'bg-slate-50',   'border' => 'border-slate-200',   'text' => 'text-slate-700',   'badge' => 'bg-slate-100 text-slate-700 border-slate-200'],
    'violet'  => ['bg' => 'bg-violet-50',  'border' => 'border-violet-200',  'text' => 'text-violet-700',  'badge' => 'bg-violet-100 text-violet-700 border-violet-200'],
    'purple'  => ['bg' => 'bg-purple-50',  'border' => 'border-purple-200',  'text' => 'text-purple-700',  'badge' => 'bg-purple-100 text-purple-700 border-purple-200'],
    'cyan'    => ['bg' => 'bg-cyan-50',    'border' => 'border-cyan-200',    'text' => 'text-cyan-700',    'badge' => 'bg-cyan-100 text-cyan-700 border-cyan-200'],
    'teal'    => ['bg' => 'bg-teal-50',    'border' => 'border-teal-200',    'text' => 'text-teal-700',    'badge' => 'bg-teal-100 text-teal-700 border-teal-200'],
    'emerald' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'badge' => 'bg-emerald-100 text-emerald-700 border-emerald-200'],
    'orange'  => ['bg' => 'bg-orange-50',  'border' => 'border-orange-200',  'text' => 'text-orange-700',  'badge' => 'bg-orange-100 text-orange-700 border-orange-200'],
    'pink'    => ['bg' => 'bg-pink-50',    'border' => 'border-pink-200',    'text' => 'text-pink-700',    'badge' => 'bg-pink-100 text-pink-700 border-pink-200'],
];

$dataSetHighlights = [
    // --- Major Documents ---
    [
        'data_set' => '9/10',
        'type' => 'major',
        'title' => 'FBI Network Chart',
        'summary' => 'A visual diagram prepared by the FBI that maps Epstein\'s network of victims, their recruiters, and a detailed timeline of alleged abuse. One of the most widely cited documents in the entire release.',
        'status' => 'available',
        'source' => 'justice.gov · Data Sets 9 & 10',
        'cta' => '/?q=FBI+network+chart',
        'cta_label' => 'Search records',
    ],
    [
        'data_set' => '12',
        'type' => 'major',
        'title' => '"National Threat Operations Center" Spreadsheet',
        'summary' => 'Summarizes uncorroborated tips sent to the FBI hotline, including thousands of mentions of public figures like Donald Trump.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 12',
        'cta' => '/drive.php?folder=dataset-12',
        'cta_label' => 'Browse data set',
    ],
    [
        'data_set' => '3/4',
        'type' => 'major',
        'title' => '2007 Draft Indictment',
        'summary' => 'A critical historical document showing that federal prosecutors had drafted a racketeering and sex trafficking indictment against Epstein and three personal assistants nearly two decades ago, before the controversial non-prosecution agreement.',
        'status' => 'available',
        'source' => 'justice.gov · Data Sets 3 & 4',
        'cta' => '/?q=draft+indictment',
        'cta_label' => 'Search records',
    ],
    [
        'data_set' => '1-4',
        'type' => 'major',
        'title' => 'Epstein\'s "Black Book" & Guest Lists',
        'summary' => 'The primary social logs including dinner guest lists and contact information for figures like Prince Andrew and Bill Clinton, found across the early data sets.',
        'status' => 'available',
        'source' => 'justice.gov · Data Sets 1–4',
        'cta' => '/?q=black+book+guest+list',
        'cta_label' => 'Search records',
    ],
    // --- Specific Notable Files ---
    [
        'data_set' => '11',
        'type' => 'specific',
        'title' => '"Jane Doe" Victim Chart',
        'summary' => 'Details specific sexual abuse acts, recruitment payments, and victim ages compiled during the federal investigation.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 11',
        'cta' => '/drive.php?folder=dataset-11',
        'cta_label' => 'Browse data set',
    ],
    [
        'data_set' => '10',
        'type' => 'specific',
        'title' => 'Prince Andrew Photos',
        'summary' => 'Includes undated photos of Andrew Mountbatten-Windsor on all fours over an unidentified woman, found among Epstein\'s seized materials.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 10',
        'cta' => '/?q=Prince+Andrew+photos',
        'cta_label' => 'Search records',
    ],
    [
        'data_set' => '9',
        'type' => 'specific',
        'title' => 'The "John Pond" Email',
        'summary' => 'An email revealing a secure address and pseudonym ("John Pond") allegedly used by former UK PM Gordon Brown in communications with Epstein.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 9',
        'cta' => '/?q=John+Pond',
        'cta_label' => 'Search records',
    ],
    [
        'data_set' => '12',
        'type' => 'specific',
        'title' => 'Elon Musk Correspondence',
        'summary' => 'Log of emails from 2012–2014 discussing holiday parties on Epstein\'s island.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 12',
        'cta' => '/?q=Elon+Musk',
        'cta_label' => 'Search records',
    ],
    [
        'data_set' => '11',
        'type' => 'specific',
        'title' => 'Sarah Ferguson Advice',
        'summary' => 'An email from May 2011 where Ferguson seeks Epstein\'s advice on how to answer Oprah Winfrey\'s questions about him.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 11',
        'cta' => '/?q=Sarah+Ferguson',
        'cta_label' => 'Search records',
    ],
    [
        'data_set' => '9',
        'type' => 'specific',
        'title' => 'Jail "Decoy" Notes',
        'summary' => 'Interview notes detailing how jail staff used a fake body made of boxes to distract the media during the removal of Epstein\'s actual remains.',
        'status' => 'available',
        'source' => 'justice.gov · Data Set 9',
        'cta' => '/?q=decoy',
        'cta_label' => 'Search records',
    ],
];

$dataSetMeta = [
    'Data Set 1-4'  => ['icon' => 'book-open',    'color' => 'violet'],
    'Data Set 3/4'  => ['icon' => 'file-text',     'color' => 'purple'],
    'Data Set 9'    => ['icon' => 'folder',         'color' => 'cyan'],
    'Data Set 9/10' => ['icon' => 'map',            'color' => 'teal'],
    'Data Set 10'   => ['icon' => 'camera',         'color' => 'emerald'],
    'Data Set 11'   => ['icon' => 'clipboard',      'color' => 'orange'],
    'Data Set 12'   => ['icon' => 'database',       'color' => 'pink'],
];

$page_title = 'Top Findings - Epstein Suite';
$meta_description = 'The most significant findings from the DOJ Epstein file releases, ranked by AI relevance score. Highlights include EFTA disclosures, flight records, and high-profile correspondence.';
$og_title = 'Top Findings from the Epstein Files';
$og_description = 'The most significant discoveries from DOJ Epstein document releases, including EFTA disclosures, flight records, and high-profile correspondence.';
require_once __DIR__ . '/includes/header_suite.php';
?>

<style>
    :root {
        --glass-bg: rgba(255, 255, 255, 0.7);
        --glass-border: rgba(255, 255, 255, 0.4);
        --finding-high: #ef4444;
        /* Red-500 */
        --finding-med: #f59e0b;
        /* Amber-500 */
    }

    .finding-card {
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.6));
        backdrop-filter: blur(20px);
        border: 1px solid white;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .finding-card:hover {
        transform: translateY(-4px) scale(1.01);
        box-shadow: 0 20px 40px -5px rgba(0, 0, 0, 0.1);
        z-index: 10;
        border-color: rgba(59, 130, 246, 0.5);
        /* Blue-500 */
    }

    .score-badge {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    .summary-text {
        display: -webkit-box;
        -webkit-line-clamp: 4;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>

<main class="flex-grow w-full max-w-7xl mx-auto px-4 py-8">

    <!-- Hero Header -->
    <div class="mb-12 text-center fade-up">
        <div
            class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-red-50 text-red-600 font-bold text-sm mb-4 border border-red-100">
            <span class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
            </span>
            AI-Identified Critical Documents
        </div>
        <h1 class="text-4xl md:text-5xl font-black text-slate-900 mb-4 tracking-tight">
            Top Findings
        </h1>
        <p class="text-lg text-slate-600 max-w-2xl mx-auto">
            These documents have been flagged by our AI analysis as having high relevance, potential shock value, or
            significant connections to key entities.
        </p>
    </div>

    <?php if (!empty($dojJan2026Highlights)): ?>
        <section id="doj-jan-2026" class="mb-14">
            <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.3em] text-red-500">January 30, 2026 Release</p>
                    <h2 class="text-2xl md:text-3xl font-black text-slate-900">DOJ 3-Million-Page Dump</h2>
                    <p class="text-sm text-slate-500 max-w-2xl mt-2">Key revelations from the U.S. Department of Justice release of more than 3 million pages of Epstein investigative files. Organized by category with direct links into the archive.</p>
                </div>
                <a href="/?q=DOJ+January+2026" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-red-600 text-white text-sm font-bold shadow-lg shadow-red-600/15 hover:bg-red-700 transition-colors">
                    Search this release
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </a>
            </div>

            <?php foreach ($jan2026Categories as $catName => $items):
                $meta = $categoryMeta[$catName] ?? ['icon' => 'file', 'color' => 'slate'];
                $c = $colorMap[$meta['color']] ?? $colorMap['slate'];
            ?>
                <div class="mb-8">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 rounded-lg <?= $c['bg'] ?> <?= $c['border'] ?> border flex items-center justify-center <?= $c['text'] ?>">
                            <svg class="w-4 h-4" data-feather="<?= $meta['icon'] ?>"></svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($catName) ?></h3>
                        <span class="text-xs font-bold <?= $c['badge'] ?> border px-2 py-0.5 rounded-full"><?= count($items) ?> findings</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($items as $callout): ?>
                            <article class="rounded-3xl border border-slate-200 bg-white p-6 flex flex-col gap-4 shadow-sm hover:border-blue-200 hover:shadow-lg hover:shadow-blue-500/5 hover:-translate-y-1 transition-all">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-[0.3em]"><?= htmlspecialchars($catName) ?></span>
                                    <span class="text-[11px] font-bold px-3 py-1 rounded-full <?= $c['badge'] ?> border">Jan 2026</span>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-slate-900 mb-2 leading-tight"><?= htmlspecialchars($callout['title']) ?></h3>
                                    <p class="text-sm text-slate-600 leading-relaxed"><?= htmlspecialchars($callout['summary']) ?></p>
                                </div>
                                <div class="flex items-center justify-between text-xs text-slate-500 mt-auto">
                                    <span><?= htmlspecialchars($callout['source']) ?></span>
                                    <a href="<?= htmlspecialchars($callout['cta']) ?>" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700">
                                        <?= htmlspecialchars($callout['cta_label']) ?>
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H7" />
                                        </svg>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($dataSetHighlights)): ?>
        <section id="notable-by-dataset" class="mb-14">
            <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.3em] text-violet-500">Epstein Files Transparency Act</p>
                    <h2 class="text-2xl md:text-3xl font-black text-slate-900">Most Notable Documents by Data Set</h2>
                    <p class="text-sm text-slate-500 max-w-2xl mt-2">Key documents from the DOJ's 3.5-million-page release organized by their originating data set. Browse each set directly or search across all files.</p>
                </div>
                <a href="/drive.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-violet-600 text-white text-sm font-bold shadow-lg shadow-violet-600/15 hover:bg-violet-700 transition-colors">
                    Browse all data sets
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                    </svg>
                </a>
            </div>

            <!-- Major Documents -->
            <div class="mb-4">
                <h3 class="text-sm font-black uppercase tracking-[0.2em] text-slate-400 mb-1">Major Documents</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
                <?php foreach ($dataSetHighlights as $item):
                    if ($item['type'] !== 'major') continue;
                    $dsLabel = 'Data Set ' . $item['data_set'];
                    $dsMeta = $dataSetMeta[$dsLabel] ?? ['icon' => 'folder', 'color' => 'slate'];
                    $c = $colorMap[$dsMeta['color']] ?? $colorMap['slate'];
                ?>
                    <article class="rounded-3xl border-2 <?= $c['border'] ?> <?= $c['bg'] ?> p-6 flex flex-col gap-4 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg <?= $c['bg'] ?> <?= $c['border'] ?> border flex items-center justify-center <?= $c['text'] ?>">
                                    <svg class="w-3.5 h-3.5" data-feather="<?= $dsMeta['icon'] ?>"></svg>
                                </div>
                                <span class="text-xs font-bold <?= $c['text'] ?> uppercase tracking-[0.3em]"><?= htmlspecialchars($dsLabel) ?></span>
                            </div>
                            <span class="text-[11px] font-bold px-3 py-1 rounded-full <?= $c['badge'] ?> border">Key Document</span>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-slate-900 mb-2 leading-tight"><?= htmlspecialchars($item['title']) ?></h3>
                            <p class="text-sm text-slate-600 leading-relaxed"><?= htmlspecialchars($item['summary']) ?></p>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-500 mt-auto">
                            <span><?= htmlspecialchars($item['source']) ?></span>
                            <a href="<?= htmlspecialchars($item['cta']) ?>" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700">
                                <?= htmlspecialchars($item['cta_label']) ?>
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H7"/>
                                </svg>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Specific Notable Files grouped by Data Set -->
            <div class="mb-4">
                <h3 class="text-sm font-black uppercase tracking-[0.2em] text-slate-400 mb-1">Specific Notable Files</h3>
            </div>
            <?php
            $specificByDataSet = [];
            foreach ($dataSetHighlights as $item) {
                if ($item['type'] === 'specific') {
                    $specificByDataSet['Data Set ' . $item['data_set']][] = $item;
                }
            }
            ?>
            <?php foreach ($specificByDataSet as $dsName => $items):
                $dsMeta = $dataSetMeta[$dsName] ?? ['icon' => 'folder', 'color' => 'slate'];
                $c = $colorMap[$dsMeta['color']] ?? $colorMap['slate'];
            ?>
                <div class="mb-8">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-8 h-8 rounded-lg <?= $c['bg'] ?> <?= $c['border'] ?> border flex items-center justify-center <?= $c['text'] ?>">
                            <svg class="w-4 h-4" data-feather="<?= $dsMeta['icon'] ?>"></svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($dsName) ?></h3>
                        <span class="text-xs font-bold <?= $c['badge'] ?> border px-2 py-0.5 rounded-full"><?= count($items) ?> file<?= count($items) !== 1 ? 's' : '' ?></span>
                        <a href="/drive.php?folder=dataset-<?= htmlspecialchars(str_replace('Data Set ', '', $dsName)) ?>" class="text-xs font-semibold text-slate-400 hover:text-blue-600 ml-auto">
                            Browse full set &rarr;
                        </a>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                        <?php foreach ($items as $callout): ?>
                            <article class="rounded-3xl border border-slate-200 bg-white p-6 flex flex-col gap-4 shadow-sm hover:border-blue-200 hover:shadow-lg hover:shadow-blue-500/5 hover:-translate-y-1 transition-all">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-bold text-slate-400 uppercase tracking-[0.3em]"><?= htmlspecialchars($dsName) ?></span>
                                    <span class="text-[11px] font-bold px-3 py-1 rounded-full <?= $c['badge'] ?> border">EFTA</span>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-slate-900 mb-2 leading-tight"><?= htmlspecialchars($callout['title']) ?></h3>
                                    <p class="text-sm text-slate-600 leading-relaxed"><?= htmlspecialchars($callout['summary']) ?></p>
                                </div>
                                <div class="flex items-center justify-between text-xs text-slate-500 mt-auto">
                                    <span><?= htmlspecialchars($callout['source']) ?></span>
                                    <a href="<?= htmlspecialchars($callout['cta']) ?>" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700">
                                        <?= htmlspecialchars($callout['cta_label']) ?>
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H7"/>
                                        </svg>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($eftaHighlights)): ?>
        <section id="efta-report" class="mb-14">
            <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.3em] text-slate-400">December 2025 Release</p>
                    <h2 class="text-2xl md:text-3xl font-black text-slate-900">EFTA Spotlight Files</h2>
                    <p class="text-sm text-slate-500 max-w-2xl mt-2">These are the reference items pulled from the DOJ transparency portal and verified media mirrors. Use them in search, Ask Epstein, and newsroom outreach.</p>
                </div>
                <a href="/efta_release.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-bold shadow-lg shadow-slate-900/15">
                    Read full analysis
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                <?php foreach ($eftaHighlights as $callout): 
                    $badgeClass = $statusBadges[$callout['status']] ?? 'bg-slate-100 text-slate-700 border border-slate-200';
                    $statusLabel = match ($callout['status']) {
                        'available' => 'DOJ Mirror',
                        'mirror' => 'Media Mirror',
                        'removed' => 'Removed · Described',
                        'forgery' => 'Forgery Warning',
                        default => 'Status'
                    };
                ?>
                    <article class="rounded-3xl border border-slate-200 bg-white p-6 flex flex-col gap-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-[0.3em]"><?= htmlspecialchars($callout['id']) ?></span>
                            <span class="text-[11px] font-bold px-3 py-1 rounded-full <?= $badgeClass ?>"><?= $statusLabel ?></span>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-slate-900 mb-2 leading-tight"><?= htmlspecialchars($callout['title']) ?></h3>
                            <p class="text-sm text-slate-600 leading-relaxed"><?= htmlspecialchars($callout['summary']) ?></p>
                        </div>
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span><?= htmlspecialchars($callout['source']) ?></span>
                            <a href="<?= htmlspecialchars($callout['cta']) ?>" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700">
                                <?= htmlspecialchars($callout['cta_label']) ?>
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H7" />
                                </svg>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (empty($topDocs)): ?>
        <div class="text-center py-20 bg-slate-50 rounded-3xl border border-slate-200">
            <div class="w-20 h-20 bg-slate-200 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-400">
                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19.428 15.428a2 2 0 00-1.022-.547l-2.384-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                </svg>
            </div>
            <h3 class="text-xl font-bold text-slate-800 mb-2">Analysis in Progress</h3>
            <p class="text-slate-500">Our AI is currently processing the archive. Check back shortly for top findings.</p>
            <div class="mt-6">
                <a href="/stats.php" class="text-blue-600 font-bold hover:underline">View Live Progress &rarr;</a>
            </div>
        </div>
    <?php else: ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($topDocs as $doc): ?>
                <div class="finding-card rounded-2xl p-6 relative overflow-hidden group">
                    <!-- Score Badge -->
                    <div class="absolute top-4 right-4 z-20">
                        <div
                            class="score-badge text-white font-black text-xs px-3 py-1.5 rounded-full shadow-lg flex items-center gap-1">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                <path
                                    d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                            </svg>
                            Relevance: <?php echo $doc['relevance_score']; ?>/10
                        </div>
                    </div>

                    <!-- Type & Date -->
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-400 mb-3 uppercase tracking-wider">
                        <span
                            class="bg-slate-100 px-2 py-0.5 rounded text-slate-500 border border-slate-200"><?php echo htmlspecialchars($doc['file_type'] ?? 'DOC'); ?></span>
                        <span>&bull;</span>
                        <span>ID: <?php echo $doc['id']; ?></span>
                    </div>

                    <!-- Title -->
                    <h3 class="text-xl font-bold text-slate-900 mb-3 leading-tight group-hover:text-blue-600 transition-colors">
                        <a href="/document.php?id=<?php echo $doc['id']; ?>" class="hover:underline">
                            <?php echo htmlspecialchars($doc['title']); ?>
                        </a>
                    </h3>

                    <!-- AI Summary -->
                    <?php if (!empty($doc['ai_summary'])): ?>
                        <div class="bg-blue-50/50 rounded-xl p-4 mb-4 border border-blue-100">
                            <div class="flex items-start gap-2">
                                <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                <p class="text-sm text-slate-700 leading-relaxed summary-text">
                                    <?php echo htmlspecialchars($doc['ai_summary']); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Footer Actions -->
                    <div class="flex items-center justify-between mt-4 pt-4 border-t border-slate-100">
                        <a href="<?php echo htmlspecialchars($doc['source_url']); ?>" target="_blank"
                            class="text-xs font-semibold text-slate-400 hover:text-slate-600 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            Source PDF
                        </a>
                        <a href="/document.php?id=<?php echo $doc['id']; ?>"
                            class="text-sm font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1 group-hover:translate-x-1 transition-transform">
                            Deep Dive
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 8l4 4m0 0l-4 4m4-4H3" />
                            </svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>