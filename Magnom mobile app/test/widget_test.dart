import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:smartlib_mobile/api_client.dart';
import 'package:smartlib_mobile/main.dart';

class _TestApiClient extends ApiClient {
  @override
  Future<void> initialize() async {}

  @override
  Future<String> beginLogin(String identifier, String password) async =>
      'challenge-token';
}

void main() {
  testWidgets('sign-in shows password and OTP security flow', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(SmartLibApp(api: _TestApiClient()));
    await tester.pumpAndSettle();

    expect(find.text('Your library,\nin your pocket.'), findsOneWidget);
    expect(find.text('Username or email'), findsOneWidget);
    expect(find.text('Password'), findsOneWidget);
    expect(find.text('Continue securely'), findsOneWidget);
    expect(
      find.textContaining('Your sign-in code expires after 10 minutes'),
      findsOneWidget,
    );
  });

  testWidgets('valid credentials advance to email verification',
      (WidgetTester tester) async {
    await tester.pumpWidget(SmartLibApp(api: _TestApiClient()));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).at(0), 'member');
    await tester.enterText(find.byType(TextFormField).at(1), 'password');
    await tester.tap(find.text('Continue securely'));
    await tester.pumpAndSettle();

    expect(find.text('One last step'), findsOneWidget);
    expect(find.text('Email verification code'), findsOneWidget);
    expect(find.text('Verify and sign in'), findsOneWidget);
  });

  test('API errors retain their server message', () {
    const error = ApiException('Your session has expired.', statusCode: 401);

    expect(error.toString(), 'Your session has expired.');
    expect(error.statusCode, 401);
  });
}
