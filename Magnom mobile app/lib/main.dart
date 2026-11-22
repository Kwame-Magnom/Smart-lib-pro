import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';

const _ink = Color(0xFF182A3A);
const _navy = Color(0xFF123047);
const _teal = Color(0xFF087E78);
const _paper = Color(0xFFF5F7F8);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const SmartLibApp());
}

class SmartLibApp extends StatefulWidget {
  const SmartLibApp({super.key, this.api});

  final ApiClient? api;

  @override
  State<SmartLibApp> createState() => _SmartLibAppState();
}

class _SmartLibAppState extends State<SmartLibApp> {
  late final ApiClient _api = widget.api ?? ApiClient();
  Map<String, dynamic>? _user;
  bool _starting = true;

  @override
  void initState() {
    super.initState();
    _restoreSession();
  }

  Future<void> _restoreSession() async {
    await _api.initialize();
    if (_api.hasToken) {
      try {
        _user = await _api.getProfile();
      } on ApiException catch (error) {
        if (error.statusCode == 401) {
          await _api.clearLocalSession();
        }
      }
    }
    if (mounted) {
      setState(() => _starting = false);
    }
  }

  void _signedIn(Map<String, dynamic> user) {
    setState(() => _user = user);
  }

  Future<void> _signedOut() async {
    try {
      await _api.signOut();
    } on ApiException {
      await _api.clearLocalSession();
    } finally {
      if (mounted) {
        setState(() => _user = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: _paper,
      colorScheme: ColorScheme.fromSeed(
        seedColor: _teal,
        primary: _teal,
        secondary: const Color(0xFFC78C4A),
        surface: Colors.white,
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: _paper,
        foregroundColor: _ink,
        centerTitle: false,
        elevation: 0,
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: Color(0xFFE2E8EB)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: Color(0xFFE2E8EB)),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 15,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: _teal,
          foregroundColor: Colors.white,
          minimumSize: const Size(0, 50),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
          textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
        ),
      ),
      textTheme: const TextTheme(
        headlineSmall: TextStyle(
          color: _ink,
          fontWeight: FontWeight.w800,
          height: 1.15,
        ),
        titleLarge: TextStyle(color: _ink, fontWeight: FontWeight.w800),
        titleMedium: TextStyle(color: _ink, fontWeight: FontWeight.w700),
        bodyMedium: TextStyle(color: Color(0xFF536575), height: 1.45),
      ),
    );

    return MaterialApp(
      title: 'SmartLib Pro',
      debugShowCheckedModeBanner: false,
      theme: theme,
      home: _starting
          ? const _StartupScreen()
          : _user == null
          ? _LoginScreen(api: _api, onSignedIn: _signedIn)
          : _LibraryShell(
              api: _api,
              user: _user!,
              onSignedOut: _signedOut,
              onUserUpdated: _signedIn,
            ),
    );
  }
}

class _StartupScreen extends StatelessWidget {
  const _StartupScreen();

  @override
  Widget build(BuildContext context) => const Scaffold(
    body: Center(child: CircularProgressIndicator(color: _teal)),
  );
}

class _LoginScreen extends StatefulWidget {
  const _LoginScreen({required this.api, required this.onSignedIn});

  final ApiClient api;
  final ValueChanged<Map<String, dynamic>> onSignedIn;

  @override
  State<_LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<_LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifier = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  String? _challenge;
  String? _error;
  bool _busy = false;
  bool _obscurePassword = true;

  @override
  void dispose() {
    _identifier.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_challenge == null) {
        _challenge = await widget.api.beginLogin(
          _identifier.text.trim(),
          _password.text,
        );
        _password.clear();
      } else {
        final user = await widget.api.verifyCode(
          _challenge!,
          _code.text.trim(),
        );
        widget.onSignedIn(user);
      }
    } on ApiException catch (error) {
      setState(() => _error = error.message);
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _configureServer() async {
    final value = TextEditingController(text: widget.api.serverUrl);
    final server = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Library server'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Enter the web address where SmartLib Pro is installed.',
            ),
            const SizedBox(height: 14),
            TextField(
              controller: value,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                labelText: 'Server URL',
                hintText: 'https://library.example.com',
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'For local Android emulators, use http://10.0.2.2/assignment.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, value.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    value.dispose();
    if (server == null || !mounted) {
      return;
    }
    try {
      await widget.api.setServerUrl(server);
      setState(() {
        _challenge = null;
        _code.clear();
        _error = null;
      });
    } on ApiException catch (error) {
      setState(() => _error = error.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final verifying = _challenge != null;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 52,
                        height: 52,
                        decoration: BoxDecoration(
                          color: _navy,
                          borderRadius: BorderRadius.circular(17),
                        ),
                        child: const Icon(
                          Icons.auto_stories_rounded,
                          color: Colors.white,
                          size: 27,
                        ),
                      ),
                      const SizedBox(width: 12),
                      const Text(
                        'SmartLib',
                        style: TextStyle(
                          color: _ink,
                          fontSize: 22,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const Spacer(),
                      IconButton(
                        tooltip: 'Configure library server',
                        onPressed: _configureServer,
                        icon: const Icon(Icons.tune_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 48),
                  Text(
                    verifying
                        ? 'One last step'
                        : 'Your library,\nin your pocket.',
                    style: Theme.of(context).textTheme.headlineSmall
                        ?.copyWith(fontSize: verifying ? 32 : 38),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    verifying
                        ? 'Enter the six-digit sign-in code sent to your registered email.'
                        : 'Sign in securely to explore the catalogue and keep up with your reading.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 28),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(22),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (_error != null) ...[
                              _ErrorBanner(message: _error!),
                              const SizedBox(height: 16),
                            ],
                            if (!verifying) ...[
                              TextFormField(
                                controller: _identifier,
                                textInputAction: TextInputAction.next,
                                autofillHints: const [
                                  AutofillHints.username,
                                  AutofillHints.email,
                                ],
                                decoration: const InputDecoration(
                                  labelText: 'Username or email',
                                  prefixIcon: Icon(Icons.person_outline),
                                ),
                                validator: (value) =>
                                    value == null || value.trim().isEmpty
                                    ? 'Enter your username or email.'
                                    : null,
                              ),
                              const SizedBox(height: 14),
                              TextFormField(
                                controller: _password,
                                obscureText: _obscurePassword,
                                textInputAction: TextInputAction.done,
                                onFieldSubmitted: (_) => _submit(),
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: const Icon(
                                    Icons.lock_outline_rounded,
                                  ),
                                  suffixIcon: IconButton(
                                    onPressed: () => setState(
                                      () =>
                                          _obscurePassword = !_obscurePassword,
                                    ),
                                    icon: Icon(
                                      _obscurePassword
                                          ? Icons.visibility_outlined
                                          : Icons.visibility_off_outlined,
                                    ),
                                  ),
                                ),
                                validator: (value) =>
                                    value == null || value.isEmpty
                                    ? 'Enter your password.'
                                    : null,
                              ),
                            ] else
                              TextFormField(
                                controller: _code,
                                autofocus: true,
                                keyboardType: TextInputType.number,
                                textInputAction: TextInputAction.done,
                                maxLength: 6,
                                onFieldSubmitted: (_) => _submit(),
                                decoration: const InputDecoration(
                                  labelText: 'Email verification code',
                                  prefixIcon: Icon(
                                    Icons.mark_email_read_outlined,
                                  ),
                                  counterText: '',
                                ),
                                validator: (value) =>
                                    value == null ||
                                        !RegExp(r'^\d{6}$').hasMatch(value)
                                    ? 'Enter the six-digit code.'
                                    : null,
                              ),
                            const SizedBox(height: 18),
                            FilledButton(
                              onPressed: _busy ? null : _submit,
                              child: _busy
                                  ? const SizedBox(
                                      height: 21,
                                      width: 21,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        color: Colors.white,
                                      ),
                                    )
                                  : Text(
                                      verifying
                                          ? 'Verify and sign in'
                                          : 'Continue securely',
                                    ),
                            ),
                            if (verifying)
                              TextButton(
                                onPressed: _busy
                                    ? null
                                    : () => setState(() {
                                        _challenge = null;
                                        _code.clear();
                                        _error = null;
                                      }),
                                child: const Text('Start over'),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 18),
                  const Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.verified_user_outlined,
                        color: _teal,
                        size: 20,
                      ),
                      SizedBox(width: 9),
                      Expanded(
                        child: Text(
                          'Your password is verified by the library server. Your sign-in code expires after 10 minutes and can only be used once.',
                          style: TextStyle(
                            color: Color(0xFF627483),
                            fontSize: 12,
                            height: 1.5,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _LibraryShell extends StatefulWidget {
  const _LibraryShell({
    required this.api,
    required this.user,
    required this.onSignedOut,
    required this.onUserUpdated,
  });

  final ApiClient api;
  final Map<String, dynamic> user;
  final Future<void> Function() onSignedOut;
  final ValueChanged<Map<String, dynamic>> onUserUpdated;

  @override
  State<_LibraryShell> createState() => _LibraryShellState();
}

class _LibraryShellState extends State<_LibraryShell> {
  int _selected = 0;

  @override
  Widget build(BuildContext context) {
    final titles = [
      'Good day',
      'Discover books',
      'My activity',
      'Courses',
      'My account',
    ];
    final pages = [
      _HomePage(api: widget.api, user: widget.user),
      _CataloguePage(api: widget.api),
      _ActivityPage(api: widget.api),
      _CoursesPage(api: widget.api),
      _ProfilePage(
        api: widget.api,
        user: widget.user,
        onUserUpdated: widget.onUserUpdated,
        onSignedOut: widget.onSignedOut,
      ),
    ];
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              titles[_selected],
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
            ),
            if (_selected == 0)
              Text(
                _displayName(widget.user),
                style: const TextStyle(
                  color: Color(0xFF71808B),
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                ),
              ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'My account and notifications',
            onPressed: () => setState(() => _selected = 4),
            icon: const Icon(Icons.account_circle_outlined),
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: IndexedStack(index: _selected, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _selected,
        onDestinationSelected: (index) => setState(() => _selected = index),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home_rounded),
            label: 'Home',
          ),
          NavigationDestination(
            icon: Icon(Icons.search_rounded),
            label: 'Explore',
          ),
          NavigationDestination(
            icon: Icon(Icons.menu_book_outlined),
            label: 'My books',
          ),
          NavigationDestination(
            icon: Icon(Icons.school_outlined),
            label: 'Courses',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline_rounded),
            label: 'Account',
          ),
        ],
      ),
    );
  }
}

class _HomePage extends StatefulWidget {
  const _HomePage({required this.api, required this.user});

  final ApiClient api;
  final Map<String, dynamic> user;

  @override
  State<_HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<_HomePage> {
  late Future<_HomeData> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_HomeData> _load() async {
    final results = await Future.wait([
      widget.api.getLoans(),
      widget.api.getReservations(),
      widget.api.getNotifications(),
      widget.api.getBooks(),
    ]);
    final loans = results[0];
    return _HomeData(
      loans: loans,
      reservations: results[1],
      unread: results[2].where((item) => item['read_at'] == null).length,
      books: results[3],
      overdue: loans
          .where(
            (loan) =>
                loan['status'] == 'Overdue' ||
                (loan['status'] == 'Active' &&
                    DateTime.tryParse('${loan['due_at']}') != null &&
                    DateTime.parse('${loan['due_at']}')
                        .isBefore(DateTime.now())),
          )
          .length,
    );
  }

  Future<void> _refresh() async {
    setState(() {
      _data = _load();
    });
    await _data;
  }

  @override
  Widget build(BuildContext context) => RefreshIndicator(
    onRefresh: _refresh,
    child: ListView(
      padding: const EdgeInsets.fromLTRB(18, 8, 18, 24),
      children: [
        _WelcomeCard(user: widget.user),
        const SizedBox(height: 18),
        FutureBuilder<_HomeData>(
          future: _data,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return _RetryCard(
                message: _friendlyError(snapshot.error),
                onRetry: _refresh,
              );
            }
            if (!snapshot.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final data = snapshot.data!;
            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: _MetricCard(
                        label: 'On loan',
                        value:
                            '${data.loans.where((e) => e['status'] == 'Active' || e['status'] == 'Overdue').length}',
                        icon: Icons.menu_book_rounded,
                        color: _teal,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _MetricCard(
                        label: 'Reservations',
                        value:
                            '${data.reservations.where((e) => e['status'] == 'Pending' || e['status'] == 'Ready').length}',
                        icon: Icons.bookmark_added_outlined,
                        color: const Color(0xFFB87931),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: _MetricCard(
                        label: 'Overdue',
                        value: '${data.overdue}',
                        icon: Icons.schedule_rounded,
                        color: const Color(0xFFB84D48),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _MetricCard(
                        label: 'Unread updates',
                        value: '${data.unread}',
                        icon: Icons.notifications_none_rounded,
                        color: _navy,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 24),
                _SectionTitle(
                  title: 'Picked for you',
                  subtitle: 'Browse what is waiting on the shelves.',
                ),
                const SizedBox(height: 10),
                if (data.books.isEmpty)
                  const _EmptyCard(
                    icon: Icons.auto_stories_outlined,
                    title: 'Your catalogue is ready to explore',
                    detail: 'New titles will appear here when they are added.',
                  )
                else
                  ...data.books
                      .take(4)
                      .map((book) => _CompactBookCard(book: book)),
              ],
            );
          },
        ),
      ],
    ),
  );
}

class _HomeData {
  const _HomeData({
    required this.loans,
    required this.reservations,
    required this.unread,
    required this.books,
    required this.overdue,
  });

  final List<Map<String, dynamic>> loans;
  final List<Map<String, dynamic>> reservations;
  final int unread;
  final List<Map<String, dynamic>> books;
  final int overdue;
}

class _WelcomeCard extends StatelessWidget {
  const _WelcomeCard({required this.user});

  final Map<String, dynamic> user;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(22),
    decoration: BoxDecoration(
      borderRadius: BorderRadius.circular(22),
      gradient: const LinearGradient(
        colors: [_navy, Color(0xFF1D5960)],
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
      ),
    ),
    child: Stack(
      children: [
        Positioned(
          right: -6,
          bottom: -16,
          child: Icon(
            Icons.auto_stories_rounded,
            color: Colors.white.withValues(alpha: .09),
            size: 120,
          ),
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'A good book changes everything.',
              style: TextStyle(
                color: Color(0xFFD5ECE9),
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 9),
            Text(
              'Welcome, ${_firstName(user)}',
              style: const TextStyle(
                color: Colors.white,
                fontSize: 25,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              '${user['role'] ?? 'Member'} · find your next read today.',
              style: const TextStyle(color: Color(0xFFE0E9EC), fontSize: 13),
            ),
          ],
        ),
      ],
    ),
  );
}

class _CataloguePage extends StatefulWidget {
  const _CataloguePage({required this.api});

  final ApiClient api;

  @override
  State<_CataloguePage> createState() => _CataloguePageState();
}

class _CataloguePageState extends State<_CataloguePage> {
  final _search = TextEditingController();
  Timer? _debounce;
  late Future<List<Map<String, dynamic>>> _books;

  @override
  void initState() {
    super.initState();
    _books = widget.api.getBooks();
    _search.addListener(_onSearch);
  }

  void _onSearch() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (mounted) {
        setState(
          () => _books = widget.api.getBooks(search: _search.text.trim()),
        );
      }
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search
      ..removeListener(_onSearch)
      ..dispose();
    super.dispose();
  }

  Future<void> _reserve(Map<String, dynamic> book) async {
    try {
      await widget.api.reserveBook(_number(book['id']));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Your reservation request was submitted.'),
          ),
        );
        setState(
          () => _books = widget.api.getBooks(search: _search.text.trim()),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  Future<void> _refresh() async {
    setState(() => _books = widget.api.getBooks(search: _search.text.trim()));
    await _books;
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(18, 4, 18, 8),
        child: TextField(
          controller: _search,
          decoration: InputDecoration(
            hintText: 'Title, author, ISBN, category...',
            prefixIcon: const Icon(Icons.search_rounded),
            suffixIcon: _search.text.isEmpty
                ? null
                : IconButton(
                    onPressed: _search.clear,
                    icon: const Icon(Icons.close_rounded),
                  ),
          ),
        ),
      ),
      Expanded(
        child: FutureBuilder<List<Map<String, dynamic>>>(
          future: _books,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return _ScrollError(
                message: _friendlyError(snapshot.error),
                onRetry: _refresh,
              );
            }
            if (!snapshot.hasData) {
              return const Center(child: CircularProgressIndicator());
            }
            final books = snapshot.data!;
            if (books.isEmpty) {
              return const _EmptyCard(
                icon: Icons.search_off_rounded,
                title: 'No books found',
                detail: 'Try another title, author, ISBN, or category.',
              );
            }
            return RefreshIndicator(
              onRefresh: _refresh,
              child: ListView.builder(
                padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
                itemCount: books.length,
                itemBuilder: (context, index) => _BookCard(
                  book: books[index],
                  onReserve: () => _reserve(books[index]),
                ),
              ),
            );
          },
        ),
      ),
    ],
  );
}

class _ActivityPage extends StatefulWidget {
  const _ActivityPage({required this.api});

  final ApiClient api;

  @override
  State<_ActivityPage> createState() => _ActivityPageState();
}

class _ActivityPageState extends State<_ActivityPage>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late Future<List<List<Map<String, dynamic>>>> _activity;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _activity = _load();
  }

  Future<List<List<Map<String, dynamic>>>> _load() =>
      Future.wait([widget.api.getLoans(), widget.api.getReservations()]);

  Future<void> _refresh() async {
    setState(() => _activity = _load());
    await _activity;
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(18, 0, 18, 8),
        child: Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
          ),
          child: TabBar(
            controller: _tabs,
            tabs: const [
              Tab(text: 'Loans & history'),
              Tab(text: 'Reservations'),
            ],
          ),
        ),
      ),
      Expanded(
        child: FutureBuilder<List<List<Map<String, dynamic>>>>(
          future: _activity,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return _ScrollError(
                message: _friendlyError(snapshot.error),
                onRetry: _refresh,
              );
            }
            if (!snapshot.hasData) {
              return const Center(child: CircularProgressIndicator());
            }
            return TabBarView(
              controller: _tabs,
              children: [
                _LoanList(items: snapshot.data![0]),
                _ReservationList(items: snapshot.data![1]),
              ],
            );
          },
        ),
      ),
    ],
  );
}

class _LoanList extends StatelessWidget {
  const _LoanList({required this.items});

  final List<Map<String, dynamic>> items;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const _EmptyCard(
        icon: Icons.menu_book_outlined,
        title: 'No borrowing activity yet',
        detail: 'Books issued to you will appear here.',
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(18, 4, 18, 20),
      itemCount: items.length,
      itemBuilder: (context, index) {
        final loan = items[index];
        final active =
            loan['status'] == 'Active' || loan['status'] == 'Overdue';
        final status = loan['status']?.toString() ?? 'Unknown';
        return Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: ListTile(
            contentPadding: const EdgeInsets.all(14),
            leading: _Cover(url: null, small: true),
            title: Text(
              '${loan['title'] ?? 'Untitled'}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 7),
              child: Text(
                '${active ? 'Due' : 'Issued'} ${_date(active ? loan['due_at'] : loan['issued_at'])}\n${loan['author'] ?? ''}',
              ),
            ),
            trailing: _StatusPill(
              label: status,
              color: status == 'Overdue'
                  ? const Color(0xFFB84D48)
                  : active
                  ? _teal
                  : const Color(0xFF6D7880),
            ),
          ),
        );
      },
    );
  }
}

class _ReservationList extends StatelessWidget {
  const _ReservationList({required this.items});

  final List<Map<String, dynamic>> items;

  @override
  Widget build(BuildContext context) {
    final active = items
        .where(
          (item) => item['status'] == 'Pending' || item['status'] == 'Ready',
        )
        .toList();
    if (active.isEmpty) {
      return const _EmptyCard(
        icon: Icons.bookmark_border_rounded,
        title: 'No active reservations',
        detail:
            'When you reserve an unavailable title, its status appears here.',
      );
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(18, 4, 18, 20),
      children: active
          .map(
            (item) => Card(
              margin: const EdgeInsets.only(bottom: 10),
              child: ListTile(
                leading: const CircleAvatar(
                  backgroundColor: Color(0xFFE7F3F1),
                  child: Icon(Icons.bookmark_added_outlined, color: _teal),
                ),
                title: Text('${item['title'] ?? 'Untitled'}'),
                subtitle: Text('Requested ${_date(item['reserved_at'])}'),
                trailing: _StatusPill(
                  label: '${item['status'] ?? 'Pending'}',
                  color: item['status'] == 'Ready' ? _teal : _navy,
                ),
              ),
            ),
          )
          .toList(),
    );
  }
}

class _CoursesPage extends StatefulWidget {
  const _CoursesPage({required this.api});

  final ApiClient api;

  @override
  State<_CoursesPage> createState() => _CoursesPageState();
}

class _CoursesPageState extends State<_CoursesPage> {
  late Future<List<List<Map<String, dynamic>>>> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<List<List<Map<String, dynamic>>>> _load() =>
      Future.wait([widget.api.getCourses(), widget.api.getCourseRequests()]);

  Future<void> _refresh() async {
    setState(() => _data = _load());
    await _data;
  }

  Future<void> _request(Map<String, dynamic> course) async {
    try {
      await widget.api.requestCourseBook(_number(course['course_book_id']));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Your course book request was submitted.'),
          ),
        );
        await _refresh();
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(
    BuildContext context,
  ) => FutureBuilder<List<List<Map<String, dynamic>>>>(
    future: _data,
    builder: (context, snapshot) {
      if (snapshot.hasError) {
        return _ScrollError(
          message: _friendlyError(snapshot.error),
          onRetry: _refresh,
        );
      }
      if (!snapshot.hasData) {
        return const Center(child: CircularProgressIndicator());
      }
      final courses = snapshot.data![0];
      final requests = snapshot.data![1];
      return RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          children: [
            const _SectionTitle(
              title: 'Course reading lists',
              subtitle: 'Request an available course book for staff approval.',
            ),
            const SizedBox(height: 12),
            if (courses.isEmpty)
              const _EmptyCard(
                icon: Icons.school_outlined,
                title: 'No course reading lists yet',
                detail: 'Active course books will appear here.',
              )
            else
              ...courses.map(
                (course) => Card(
                  margin: const EdgeInsets.only(bottom: 10),
                  child: Padding(
                    padding: const EdgeInsets.all(15),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${course['course_name'] ?? 'Course'} · ${course['course_code'] ?? ''}',
                          style: const TextStyle(
                            color: _teal,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          '${course['title'] ?? 'Untitled'}',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        Text('${course['author'] ?? ''}'),
                        const SizedBox(height: 10),
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                '${_number(course['available_copies'])} available',
                                style: const TextStyle(fontSize: 12),
                              ),
                            ),
                            TextButton.icon(
                              onPressed: () => _request(course),
                              icon: const Icon(Icons.send_rounded, size: 17),
                              label: const Text('Request'),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            if (requests.isNotEmpty) ...[
              const SizedBox(height: 12),
              const _SectionTitle(
                title: 'My requests',
                subtitle: 'Track decisions on your requested course books.',
              ),
              const SizedBox(height: 8),
              ...requests.map(
                (request) => Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    title: Text('${request['title'] ?? 'Course book'}'),
                    subtitle: Text(
                      '${request['course_name'] ?? ''} · ${_date(request['requested_at'])}',
                    ),
                    trailing: _StatusPill(
                      label: '${request['status'] ?? 'Pending'}',
                      color: request['status'] == 'Approved'
                          ? _teal
                          : request['status'] == 'Rejected'
                          ? const Color(0xFFB84D48)
                          : _navy,
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      );
    },
  );
}

class _ProfilePage extends StatefulWidget {
  const _ProfilePage({
    required this.api,
    required this.user,
    required this.onUserUpdated,
    required this.onSignedOut,
  });

  final ApiClient api;
  final Map<String, dynamic> user;
  final ValueChanged<Map<String, dynamic>> onUserUpdated;
  final Future<void> Function() onSignedOut;

  @override
  State<_ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<_ProfilePage> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _schoolId;
  late Future<List<Map<String, dynamic>>> _notifications;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: '${widget.user['display_name'] ?? ''}');
    _schoolId = TextEditingController(
      text: '${widget.user['school_id'] ?? ''}',
    );
    _notifications = widget.api.getNotifications();
  }

  @override
  void dispose() {
    _name.dispose();
    _schoolId.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }
    setState(() => _saving = true);
    try {
      final user = await widget.api.updateProfile({
        'display_name': _name.text.trim(),
        'school_id': _schoolId.text.trim(),
      });
      widget.onUserUpdated(user);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Your profile was updated.')),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) {
        setState(() => _saving = false);
      }
    }
  }

  Future<void> _markRead(int id) async {
    try {
      await widget.api.markNotificationRead(id);
      if (mounted) {
        setState(() => _notifications = widget.api.getNotifications());
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  Future<void> _retryNotifications() async {
    final request = widget.api.getNotifications();
    setState(() => _notifications = request);
    await request;
  }

  Future<void> _configureServer() async {
    final value = TextEditingController(text: widget.api.serverUrl);
    final server = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Library server'),
        content: TextField(
          controller: value,
          keyboardType: TextInputType.url,
          decoration: const InputDecoration(labelText: 'Server URL'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, value.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    value.dispose();
    if (server == null || !mounted) {
      return;
    }
    try {
      await widget.api.setServerUrl(server);
      await widget.onSignedOut();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
    children: [
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: _navy,
          borderRadius: BorderRadius.circular(20),
        ),
        child: Row(
          children: [
            CircleAvatar(
              radius: 28,
              backgroundColor: Colors.white.withValues(alpha: .13),
              child: const Icon(
                Icons.person_rounded,
                color: Colors.white,
                size: 30,
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _displayName(widget.user),
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  Text(
                    '${widget.user['role'] ?? 'Member'} · ${widget.user['username'] ?? ''}',
                    style: const TextStyle(color: Color(0xFFDCE8EB)),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      const SizedBox(height: 20),
      const _SectionTitle(title: 'Personal details'),
      const SizedBox(height: 10),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Form(
            key: _formKey,
            child: Column(
              children: [
                TextFormField(
                  controller: _name,
                  maxLength: 100,
                  decoration: const InputDecoration(
                    labelText: 'Display name',
                    counterText: '',
                  ),
                ),
                const SizedBox(height: 12),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.email_outlined),
                  title: Text('${widget.user['email'] ?? ''}'),
                  subtitle: const Text('Sign-in email'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _schoolId,
                  maxLength: 50,
                  decoration: const InputDecoration(
                    labelText: 'School ID',
                    counterText: '',
                  ),
                ),
                const SizedBox(height: 14),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: _saving ? null : _save,
                    child: _saving
                        ? const SizedBox(
                            height: 19,
                            width: 19,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Save changes'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      const SizedBox(height: 20),
      const _SectionTitle(
        title: 'Notifications',
        subtitle: 'Updates from your library.',
      ),
      const SizedBox(height: 8),
      FutureBuilder<List<Map<String, dynamic>>>(
        future: _notifications,
        builder: (context, snapshot) {
          if (snapshot.hasError) {
            return _RetryCard(
              message: _friendlyError(snapshot.error),
              onRetry: _retryNotifications,
            );
          }
          if (!snapshot.hasData) {
            return const Padding(
              padding: EdgeInsets.all(18),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          if (snapshot.data!.isEmpty) {
            return const _EmptyCard(
              icon: Icons.notifications_none_rounded,
              title: 'You are all caught up',
              detail: 'Library updates will be shown here.',
            );
          }
          return Column(
            children: snapshot.data!
                .map(
                  (notification) => Card(
                    margin: const EdgeInsets.only(bottom: 8),
                    child: ListTile(
                      leading: Icon(
                        notification['read_at'] == null
                            ? Icons.mark_email_unread_outlined
                            : Icons.mark_email_read_outlined,
                        color: notification['read_at'] == null
                            ? _teal
                            : const Color(0xFF89959D),
                      ),
                      title: Text('${notification['message'] ?? ''}'),
                      subtitle: Text(_date(notification['created_at'])),
                      onTap: notification['read_at'] == null
                          ? () => _markRead(_number(notification['id']))
                          : null,
                    ),
                  ),
                )
                .toList(),
          );
        },
      ),
      const SizedBox(height: 18),
      Card(
        child: Column(
          children: [
            ListTile(
              leading: const Icon(Icons.dns_outlined, color: _teal),
              title: const Text('Library server'),
              subtitle: Text(
                widget.api.serverUrl,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: _configureServer,
            ),
            const Divider(height: 1, indent: 16, endIndent: 16),
            ListTile(
              leading: const Icon(Icons.verified_user_outlined, color: _teal),
              title: const Text('Secure sign-in'),
              subtitle: const Text('Email verification · protected session'),
            ),
          ],
        ),
      ),
      const SizedBox(height: 16),
      OutlinedButton.icon(
        onPressed: widget.onSignedOut,
        icon: const Icon(Icons.logout_rounded),
        label: const Text('Sign out'),
        style: OutlinedButton.styleFrom(
          foregroundColor: const Color(0xFF9A403B),
          minimumSize: const Size(0, 50),
          side: const BorderSide(color: Color(0xFFE8C9C6)),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
    ],
  );
}

class _BookCard extends StatelessWidget {
  const _BookCard({required this.book, required this.onReserve});

  final Map<String, dynamic> book;
  final VoidCallback onReserve;

  @override
  Widget build(BuildContext context) {
    final available = _number(book['available_copies']);
    final total = _number(book['physical_copies']);
    return Card(
      margin: const EdgeInsets.only(bottom: 11),
      child: Padding(
        padding: const EdgeInsets.all(13),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Cover(url: book['cover_image_url']?.toString()),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${book['title'] ?? 'Untitled'}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 4),
                  Text('${book['author'] ?? 'Unknown author'}'),
                  const SizedBox(height: 8),
                  Text(
                    '${book['category'] ?? 'General'}${book['publisher'] == null ? '' : ' · ${book['publisher']}'}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 12),
                  ),
                  const SizedBox(height: 9),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          available > 0
                              ? '$available of $total available'
                              : total > 0
                              ? 'Currently borrowed'
                              : 'Recommendation',
                          style: TextStyle(
                            color: available > 0
                                ? _teal
                                : const Color(0xFF7C6B57),
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                      if (available == 0 && total > 0)
                        TextButton(
                          onPressed: onReserve,
                          child: const Text('Reserve'),
                        ),
                    ],
                  ),
                  if (book['shelf_code'] != null &&
                      '${book['shelf_code']}'.isNotEmpty)
                    Text(
                      'Shelf ${book['shelf_code']}',
                      style: const TextStyle(fontSize: 11),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CompactBookCard extends StatelessWidget {
  const _CompactBookCard({required this.book});

  final Map<String, dynamic> book;

  @override
  Widget build(BuildContext context) => Card(
    margin: const EdgeInsets.only(bottom: 8),
    child: ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 5),
      leading: _Cover(url: book['cover_image_url']?.toString(), small: true),
      title: Text(
        '${book['title'] ?? 'Untitled'}',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Text('${book['author'] ?? ''}'),
      trailing: Text(
        '${_number(book['available_copies'])} available',
        style: const TextStyle(
          color: _teal,
          fontSize: 11,
          fontWeight: FontWeight.w700,
        ),
      ),
    ),
  );
}

class _Cover extends StatelessWidget {
  const _Cover({required this.url, this.small = false});

  final String? url;
  final bool small;

  @override
  Widget build(BuildContext context) {
    final width = small ? 44.0 : 76.0;
    final height = small ? 58.0 : 102.0;
    final parsed = url == null ? null : Uri.tryParse(url!);
    if (parsed != null && parsed.scheme == 'https') {
      return ClipRRect(
        borderRadius: BorderRadius.circular(9),
        child: Image.network(
          url!,
          width: width,
          height: height,
          fit: BoxFit.cover,
          errorBuilder: (context, error, stackTrace) =>
              _placeholder(width, height),
        ),
      );
    }
    return _placeholder(width, height);
  }

  Widget _placeholder(double width, double height) => Container(
    width: width,
    height: height,
    decoration: BoxDecoration(
      color: const Color(0xFFE8EFEE),
      borderRadius: BorderRadius.circular(9),
    ),
    child: const Icon(Icons.menu_book_rounded, color: _teal, size: 25),
  );
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(15),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: color.withValues(alpha: .10),
              borderRadius: BorderRadius.circular(13),
            ),
            child: Icon(icon, color: color, size: 21),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  value,
                  style: const TextStyle(
                    color: _ink,
                    fontSize: 20,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11),
                ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.title, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(title, style: Theme.of(context).textTheme.titleLarge),
      if (subtitle != null) ...[
        const SizedBox(height: 3),
        Text(subtitle!, style: Theme.of(context).textTheme.bodyMedium),
      ],
    ],
  );
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    decoration: BoxDecoration(
      color: color.withValues(alpha: .1),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Text(
      label,
      style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700),
    ),
  );
}

class _EmptyCard extends StatelessWidget {
  const _EmptyCard({
    required this.icon,
    required this.title,
    required this.detail,
  });

  final IconData icon;
  final String title;
  final String detail;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 34, horizontal: 22),
    child: Column(
      children: [
        Icon(icon, size: 38, color: const Color(0xFF9DAAAF)),
        const SizedBox(height: 12),
        Text(
          title,
          textAlign: TextAlign.center,
          style: const TextStyle(
            color: _ink,
            fontSize: 16,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 6),
        Text(detail, textAlign: TextAlign.center),
      ],
    ),
  );
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: const Color(0xFFFFF1EF),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Icon(
          Icons.error_outline_rounded,
          color: Color(0xFFAA4139),
          size: 19,
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            message,
            style: const TextStyle(color: Color(0xFF8D3732), fontSize: 13),
          ),
        ),
      ],
    ),
  );
}

class _RetryCard extends StatelessWidget {
  const _RetryCard({required this.message, required this.onRetry});

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          _ErrorBanner(message: message),
          const SizedBox(height: 10),
          TextButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('Try again'),
          ),
        ],
      ),
    ),
  );
}

class _ScrollError extends StatelessWidget {
  const _ScrollError({required this.message, required this.onRetry});

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(18),
    children: [_RetryCard(message: message, onRetry: onRetry)],
  );
}

String _displayName(Map<String, dynamic> user) {
  final name = '${user['display_name'] ?? ''}'.trim();
  return name.isNotEmpty ? name : '${user['username'] ?? 'Member'}';
}

String _firstName(Map<String, dynamic> user) =>
    _displayName(user).split(RegExp(r'\s+')).first;

String _date(dynamic value) {
  if (value == null || '$value'.isEmpty) {
    return 'Date unavailable';
  }
  final date = DateTime.tryParse('$value');
  if (date == null) {
    return '$value';
  }
  const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];
  return '${months[date.month - 1]} ${date.day}, ${date.year}';
}

int _number(dynamic value) => int.tryParse('$value') ?? 0;

String _friendlyError(Object? error) {
  if (error is ApiException) {
    return error.message;
  }
  return 'Something went wrong while loading your library. Please try again.';
}
