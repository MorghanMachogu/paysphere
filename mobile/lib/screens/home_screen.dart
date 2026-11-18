import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import '../services/api.dart';
import '../services/auth_provider.dart';
import 'change_password_screen.dart';

const _paid = Color(0xFF1F7A5C);

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user!;
    final pages = [const TodayTab(), const PayslipsTab(), const LeaveTab(), const ProfileTab()];
    return Scaffold(
      appBar: AppBar(title: Text('Hello, ${user['first_name']}'), actions: [
        PopupMenuButton<String>(
          onSelected: (v) {
            if (v == 'password') {
              Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ChangePasswordScreen()));
            } else {
              context.read<AuthProvider>().logout();
            }
          },
          itemBuilder: (_) => const [
            PopupMenuItem(value: 'password', child: Text('Change password')),
            PopupMenuItem(value: 'logout', child: Text('Log out')),
          ],
        ),
      ]),
      body: pages[_tab],
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.access_time), label: 'Today'),
          NavigationDestination(icon: Icon(Icons.receipt_long), label: 'Payslips'),
          NavigationDestination(icon: Icon(Icons.event_available), label: 'Leave'),
          NavigationDestination(icon: Icon(Icons.person_outline), label: 'Profile'),
        ],
      ),
    );
  }
}

void _toast(BuildContext context, String msg, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: error ? Colors.red.shade800 : null));
}

/// Loads data with pull-to-refresh and an error state with a retry button.
class _Loader extends StatefulWidget {
  final Future<dynamic> Function() load;
  final Widget Function(BuildContext, dynamic, VoidCallback reload) builder;
  const _Loader({required this.load, required this.builder});
  @override
  State<_Loader> createState() => _LoaderState();
}

class _LoaderState extends State<_Loader> {
  late Future<dynamic> _future = widget.load();
  void _reload() => setState(() => _future = widget.load());

  @override
  Widget build(BuildContext context) {
    return FutureBuilder(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
        if (snap.hasError) {
          return Center(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('${snap.error}', textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _reload, child: const Text('Try again')),
          ])));
        }
        return RefreshIndicator(onRefresh: () async => _reload(), child: widget.builder(context, snap.data, _reload));
      },
    );
  }
}

// ---------------------------------------------------------------------
class TodayTab extends StatelessWidget {
  const TodayTab({super.key});

  Future<Map<String, dynamic>> _load() async {
    final results = await Future.wait([
      Api.instance.get('attendance.php', {'action': 'today'}),
      Api.instance.get('payroll.php', {'action': 'my_payslips'}),
      Api.instance.get('leave.php', {'action': 'balance'}),
    ]);
    return {'today': results[0], 'payslips': results[1], 'balance': results[2]};
  }

  @override
  Widget build(BuildContext context) {
    return _Loader(load: _load, builder: (context, data, reload) {
      final today = data['today'] as Map<String, dynamic>?;
      final payslips = data['payslips'] as List;
      final annual = (data['balance'] as List).cast<Map<String, dynamic>>()
          .where((b) => b['code'] == 'ANNUAL').toList();

      Future<void> clock(String action) async {
        try {
          final res = await Api.instance.post('attendance.php?action=$action');
          if (context.mounted) _toast(context, res['message']);
          reload();
        } catch (e) {
          if (context.mounted) _toast(context, '$e', error: true);
        }
      }

      final clockedIn = today?['clock_in'] != null;
      final clockedOut = today?['clock_out'] != null;
      return ListView(padding: const EdgeInsets.all(16), children: [
        Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('Attendance today', style: Theme.of(context).textTheme.titleMedium),
          Text(DateFormat('EEEE, d MMMM').format(DateTime.now())),
          const SizedBox(height: 16),
          if (today?['status'] == 'on_leave') const Text('You are on approved leave today.')
          else if (!clockedIn) FilledButton.icon(onPressed: () => clock('clock_in'), icon: const Icon(Icons.login), label: const Text('Clock in'))
          else ...[
            Text('Clocked in at ${today!['clock_in'].toString().substring(0, 5)}${today['status'] == 'late' ? ' (late)' : ''}'
                '${clockedOut ? ', out at ${today['clock_out'].toString().substring(0, 5)}' : ''}'),
            if (!clockedOut) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(onPressed: () => clock('clock_out'), icon: const Icon(Icons.logout), label: const Text('Clock out')),
            ],
          ],
        ]))),
        Card(child: Padding(padding: const EdgeInsets.all(20), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('Latest pay', style: Theme.of(context).textTheme.titleMedium),
          if (payslips.isEmpty) const Text('Your payslips will appear after your first approved payroll.')
          else ...[
            Text(periodName(payslips[0]['period_month'], payslips[0]['period_year'])),
            const SizedBox(height: 8),
            Text(kes(payslips[0]['net_pay']), style: TextStyle(fontSize: 30, fontWeight: FontWeight.w700,
                color: payslips[0]['payment_status'] == 'paid' ? _paid : Theme.of(context).colorScheme.primary)),
            Text(payslips[0]['payment_status'] == 'paid' ? 'Paid' : 'Approved, awaiting payment'),
          ],
        ]))),
        if (annual.isNotEmpty)
          Card(child: ListTile(
            title: const Text('Annual leave'),
            subtitle: Text('${days(annual[0]['remaining'])} of ${annual[0]['entitlement']} days left this year'),
          )),
      ]);
    });
  }
}

// ---------------------------------------------------------------------
class PayslipsTab extends StatelessWidget {
  const PayslipsTab({super.key});

  @override
  Widget build(BuildContext context) {
    return _Loader(load: () => Api.instance.get('payroll.php', {'action': 'my_payslips'}), builder: (context, data, _) {
      final list = (data as List).cast<Map<String, dynamic>>();
      if (list.isEmpty) {
        return ListView(children: const [Padding(padding: EdgeInsets.all(32),
            child: Text('No payslips yet. They appear once HR approves a month’s payroll.', textAlign: TextAlign.center))]);
      }
      return ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: list.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          final p = list[i];
          return Card(child: ExpansionTile(
            title: Text(periodName(p['period_month'], p['period_year'])),
            subtitle: Text('Net ${kes(p['net_pay'])} · ${p['payment_status'] == 'paid' ? 'Paid' : 'Awaiting payment'}'),
            childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            children: [
              for (final row in [
                ['Gross pay', p['gross_pay']], ['NSSF', p['nssf_employee']], ['SHIF', p['shif']],
                ['Housing Levy', p['housing_levy']], ['PAYE', p['paye']], ['Total deductions', p['total_deductions']],
                ['Net pay', p['net_pay']],
              ])
                Padding(padding: const EdgeInsets.symmetric(vertical: 3), child: Row(children: [
                  Expanded(child: Text(row[0] as String)),
                  Text(kes(row[1]), style: TextStyle(fontWeight: row[0] == 'Net pay' ? FontWeight.w700 : FontWeight.normal)),
                ])),
              const SizedBox(height: 8),
              const Text('The printable payslip is in the PaySphere web portal.', style: TextStyle(fontSize: 12)),
            ],
          ));
        },
      );
    });
  }
}

// ---------------------------------------------------------------------
class LeaveTab extends StatelessWidget {
  const LeaveTab({super.key});

  Future<Map<String, dynamic>> _load() async {
    final r = await Future.wait([
      Api.instance.get('leave.php', {'action': 'types'}),
      Api.instance.get('leave.php', {'action': 'balance'}),
      Api.instance.get('leave.php'),
    ]);
    return {'types': r[0], 'balance': r[1], 'requests': r[2]};
  }

  @override
  Widget build(BuildContext context) {
    return _Loader(load: _load, builder: (context, data, reload) {
      final balance = (data['balance'] as List).cast<Map<String, dynamic>>();
      final requests = (data['requests'] as List).cast<Map<String, dynamic>>();
      return ListView(padding: const EdgeInsets.all(16), children: [
        FilledButton.icon(
          icon: const Icon(Icons.add),
          label: const Text('Apply for leave'),
          onPressed: () async {
            final ok = await showModalBottomSheet<bool>(context: context, isScrollControlled: true,
                builder: (_) => _ApplyLeaveSheet(types: (data['types'] as List).cast<Map<String, dynamic>>()));
            if (ok == true) reload();
          },
        ),
        const SizedBox(height: 12),
        Card(child: Column(children: [
          for (final b in balance)
            ListTile(dense: true, title: Text('${b['name']}'),
                trailing: Text('${days(b['remaining'])} / ${b['entitlement']} days')),
        ])),
        const SizedBox(height: 8),
        Text('My requests', style: Theme.of(context).textTheme.titleMedium),
        if (requests.isEmpty) const Padding(padding: EdgeInsets.all(8), child: Text('No leave requests yet.')),
        for (final r in requests)
          Card(child: ListTile(
            title: Text('${r['leave_type']} · ${days(r['days'])} days'),
            subtitle: Text('${r['start_date']} to ${r['end_date']}'
                '${r['review_comment'] != null ? '\n“${r['review_comment']}”' : ''}'),
            trailing: r['status'] == 'pending'
                ? TextButton(child: const Text('Cancel'), onPressed: () async {
                    try {
                      await Api.instance.post('leave.php?action=cancel', {'id': r['id']});
                      reload();
                    } catch (e) {
                      if (context.mounted) _toast(context, '$e', error: true);
                    }
                  })
                : Text('${r['status']}'.toUpperCase().substring(0, 1) + '${r['status']}'.substring(1)),
          )),
      ]);
    });
  }
}

class _ApplyLeaveSheet extends StatefulWidget {
  final List<Map<String, dynamic>> types;
  const _ApplyLeaveSheet({required this.types});
  @override
  State<_ApplyLeaveSheet> createState() => _ApplyLeaveSheetState();
}

class _ApplyLeaveSheetState extends State<_ApplyLeaveSheet> {
  int? _type;
  DateTimeRange? _range;
  final _reason = TextEditingController();
  bool _busy = false;
  final _fmt = DateFormat('yyyy-MM-dd');

  Future<void> _submit() async {
    if (_type == null || _range == null) {
      _toast(context, 'Choose a leave type and dates.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final res = await Api.instance.post('leave.php?action=apply', {
        'leave_type_id': _type, 'start_date': _fmt.format(_range!.start), 'end_date': _fmt.format(_range!.end),
        'reason': _reason.text,
      });
      if (mounted) {
        _toast(context, res['message']);
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) _toast(context, '$e', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('Apply for leave', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 16),
        DropdownButtonFormField<int>(
          value: _type,
          decoration: const InputDecoration(labelText: 'Leave type'),
          items: [for (final t in widget.types) DropdownMenuItem(value: int.parse('${t['id']}'), child: Text('${t['name']}'))],
          onChanged: (v) => setState(() => _type = v),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          icon: const Icon(Icons.date_range),
          label: Text(_range == null ? 'Choose dates' : '${_fmt.format(_range!.start)} to ${_fmt.format(_range!.end)}'),
          onPressed: () async {
            final now = DateTime.now();
            final r = await showDateRangePicker(context: context, firstDate: now.subtract(const Duration(days: 30)),
                lastDate: DateTime(now.year + 1, 12, 31));
            if (r != null) setState(() => _range = r);
          },
        ),
        const SizedBox(height: 12),
        TextField(controller: _reason, decoration: const InputDecoration(labelText: 'Reason (optional)'), maxLines: 2),
        const SizedBox(height: 8),
        const Text('Days are counted Monday to Friday.', style: TextStyle(fontSize: 12)),
        const SizedBox(height: 16),
        FilledButton(onPressed: _busy ? null : _submit, child: Text(_busy ? 'Submitting…' : 'Submit request')),
      ]),
    );
  }
}

// ---------------------------------------------------------------------
class ProfileTab extends StatelessWidget {
  const ProfileTab({super.key});

  @override
  Widget build(BuildContext context) {
    return _Loader(load: () => Api.instance.get('employees.php', {'action': 'me'}), builder: (context, data, reload) {
      final e = Map<String, dynamic>.from(data);
      final rows = [
        ['Employee number', e['employee_number']], ['Department', e['department']], ['Position', e['position']],
        ['Hire date', e['hire_date']], ['Mobile', e['phone_number']], ['Personal email', e['personal_email']],
        ['KRA PIN', e['kra_pin']], ['NSSF number', e['nssf_number']], ['SHIF number', e['shif_number']],
      ];
      return ListView(padding: const EdgeInsets.all(16), children: [
        Text('${e['first_name']} ${e['last_name']}', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 8),
        Card(child: Column(children: [
          for (final r in rows) ListTile(dense: true, title: Text('${r[0]}'), trailing: Text('${r[1] ?? '—'}')),
        ])),
        OutlinedButton(
          child: const Text('Update contact details'),
          onPressed: () async {
            final phone = TextEditingController(text: e['phone_number'] ?? '');
            final email = TextEditingController(text: e['personal_email'] ?? '');
            final save = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(
              title: const Text('Contact details'),
              content: Column(mainAxisSize: MainAxisSize.min, children: [
                TextField(controller: phone, decoration: const InputDecoration(labelText: 'Mobile number'), keyboardType: TextInputType.phone),
                const SizedBox(height: 12),
                TextField(controller: email, decoration: const InputDecoration(labelText: 'Personal email'), keyboardType: TextInputType.emailAddress),
              ]),
              actions: [
                TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Save')),
              ],
            ));
            if (save != true) return;
            try {
              await Api.instance.put('employees.php?action=me', {'phone_number': phone.text, 'personal_email': email.text});
              if (context.mounted) _toast(context, 'Contact details saved');
              reload();
            } catch (err) {
              if (context.mounted) _toast(context, '$err', error: true);
            }
          },
        ),
        const SizedBox(height: 8),
        const Text('To change bank, M-Pesa or statutory details, contact HR.', style: TextStyle(fontSize: 12)),
      ]);
    });
  }
}
