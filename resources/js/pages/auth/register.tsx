import { HttpResponseError } from '@inertiajs/core';
import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import {
    register,
    verifyOtp,
} from '@/actions/App/Http/Controllers/Auth/AuthController';
import InputError from '@/components/input-error';
import GoogleLoginButton from '@/components/google-login-button';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

type Props = {
    passwordRules: string;
};

function requestErrorMessage(error: unknown): string {
    if (error instanceof HttpResponseError) {
        const body: unknown = error.response.data;

        if (
            body &&
            typeof body === 'object' &&
            'message' in body &&
            typeof body.message === 'string'
        ) {
            return body.message;
        }
    }

    return 'Unable to complete the request. Please try again.';
}

function VerifyPhone({ phone }: { phone: string }) {
    const { data, setData, post, errors, processing } = useHttp({
        phone,
        otp: '',
    });
    const [message, setMessage] = useState('');

    setLayoutProps({
        title: 'Verify your phone number',
        description: `Enter the six-digit code sent to ${phone}.`,
    });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        setMessage('');

        try {
            await post(verifyOtp.url());
            router.visit(login());
        } catch (error) {
            setMessage(requestErrorMessage(error));
        }
    }

    return (
        <>
            <Head title="Verify phone number" />
            <form onSubmit={submit} className="flex flex-col gap-6">
                <fieldset disabled={processing} className="grid gap-6">
                    <input type="hidden" name="phone" value={data.phone} />
                    <InputError message={errors.phone} />
                    <div className="grid gap-2">
                        <Label htmlFor="otp">Verification code</Label>
                        <Input
                            id="otp"
                            name="otp"
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="[0-9]{6}"
                            minLength={6}
                            maxLength={6}
                            required
                            autoFocus
                            placeholder="123456"
                            value={data.otp}
                            onChange={(event) =>
                                setData('otp', event.target.value)
                            }
                        />
                        <InputError message={errors.otp} />
                    </div>
                    {message && <InputError message={message} role="alert" />}
                    <Button
                        type="submit"
                        className="mt-2 w-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Verify phone number
                    </Button>
                </fieldset>
            </form>
        </>
    );
}

export default function Register({ passwordRules }: Props) {
    const { data, setData, post, errors, processing, reset } = useHttp({
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });
    const [verificationPhone, setVerificationPhone] = useState<string | null>(
        null,
    );
    const [message, setMessage] = useState('');

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (processing) return;
        setMessage('');

        try {
            await post(register.url());
            reset('password', 'password_confirmation');
            setVerificationPhone(data.phone);
        } catch (error) {
            setMessage(requestErrorMessage(error));
        }
    }

    if (verificationPhone !== null) {
        return <VerifyPhone phone={verificationPhone} />;
    }

    return (
        <>
            <Head title="Register" />
            <GoogleLoginButton />
            <form onSubmit={submit} className="flex flex-col gap-6">
                <fieldset disabled={processing} className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="name"
                            name="name"
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                            placeholder="Full name"
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={2}
                            autoComplete="email"
                            name="email"
                            value={data.email}
                            onChange={(event) =>
                                setData('email', event.target.value)
                            }
                            placeholder="email@example.com"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="phone">Phone number</Label>
                        <Input
                            id="phone"
                            type="tel"
                            required
                            tabIndex={3}
                            autoComplete="tel"
                            name="phone"
                            value={data.phone}
                            onChange={(event) =>
                                setData('phone', event.target.value)
                            }
                            placeholder="+20 10 1234 5678"
                        />
                        <InputError message={errors.phone} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            required
                            tabIndex={4}
                            autoComplete="new-password"
                            name="password"
                            value={data.password}
                            onChange={(event) =>
                                setData('password', event.target.value)
                            }
                            placeholder="Password"
                            passwordrules={passwordRules}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Confirm password
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            required
                            tabIndex={5}
                            autoComplete="new-password"
                            name="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(event) =>
                                setData(
                                    'password_confirmation',
                                    event.target.value,
                                )
                            }
                            placeholder="Confirm password"
                            passwordrules={passwordRules}
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    {message && <InputError message={message} role="alert" />}
                    <Button
                        disabled={processing}
                        type="submit"
                        className="mt-2 w-full"
                        tabIndex={6}
                        data-test="register-user-button"
                    >
                        {processing && <Spinner />}
                        Create account
                    </Button>
                </fieldset>

                <div className="text-muted-foreground text-center text-sm">
                    Already have an account?{' '}
                    <TextLink href={login()} tabIndex={7}>
                        Log in
                    </TextLink>
                </div>
            </form>
        </>
    );
}

Register.layout = {
    title: 'Create an account',
    description: 'Enter your details below to create your account',
};
