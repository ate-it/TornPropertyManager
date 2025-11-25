using Avalonia;
using Avalonia.Controls.ApplicationLifetimes;

namespace TornPropertyManager;

public partial class App : Application
{
    public override void OnFrameworkInitializationCompleted()
    {
        if (ApplicationLifetime is IClassicDesktopStyleApplicationLifetime desktop)
        {
            var settingsService = new SettingsService();
            var apiService = new TornApiService();

            var settings = settingsService.Load();

            if (string.IsNullOrWhiteSpace(settings.ApiKey))
            {
                // No key yet – show API key window first
                desktop.MainWindow = new ApiKeyWindow(settingsService, apiService);
            }
            else
            {
                // Key exists – go straight to main window
                desktop.MainWindow = new MainWindow(settingsService, apiService);
            }
        }

        base.OnFrameworkInitializationCompleted();
    }
}
