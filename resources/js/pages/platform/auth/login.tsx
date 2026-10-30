import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import PlatformLoginController from '@/actions/App/Http/Controllers/Platform/Auth/PlatformLoginController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';

export default function PlatformLogin({ status }: { status?: string }) {
    const t = useT();

    return (
        <div className="flex min-h-svh items-center justify-center bg-slate-50 p-6 dark:bg-slate-950">
            <Head title={t('Platform sign in')} />
            <div className="w-full max-w-sm space-y-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <span className="flex size-11 items-center justify-center rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900">
                        <ShieldCheck className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-xl font-semibold">{t('Platform sign in')}</h1>
                        <p className="text-sm text-muted-foreground">
                            {t('Restricted to platform staff.')}
                        </p>
                    </div>
                </div>

                {status && (
                    <p className="text-center text-sm font-medium text-emerald-600">{status}</p>
                )}

                <Form
                    {...PlatformLoginController.store.form()}
                    resetOnSuccess={['password']}
                    className="grid gap-5 rounded-xl border bg-card p-6 shadow-xs"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">{t('Email address')}</Label>
                                <Input id="email" name="email" type="email" autoComplete="username" required autoFocus />
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">{t('Password')}</Label>
                                <PasswordInput id="password" name="password" autoComplete="current-password" required />
                                <InputError message={errors.password} />
                            </div>
                            <div className="flex items-center gap-3">
                                <Checkbox id="remember" name="remember" value="1" />
                                <Label htmlFor="remember">{t('Remember me')}</Label>
                            </div>
                            <Button type="submit" disabled={processing} className="w-full">
                                {processing && <Spinner />}
                                {t('Log in')}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}
