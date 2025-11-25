using Avalonia.Controls;
using Avalonia.Interactivity;
using System.Threading.Tasks;

namespace TornPropertyManager;

public partial class ApiKeyWindow : Window
{
    private readonly SettingsService _settingsService;
    private readonly TornApiService _apiService;

    // 👇 Parameterless ctor required for XAML loader (even if you don't use it)
    public ApiKeyWindow()
        : this(new SettingsService(), new TornApiService())
    {
    }

    public ApiKeyWindow(SettingsService settingsService, TornApiService apiService)
    {
        InitializeComponent();
        _settingsService = settingsService;
        _apiService = apiService;

        var settings = _settingsService.Load();
        if (!string.IsNullOrWhiteSpace(settings.ApiKey))
        {
            ApiKeyTextBox.Text = settings.ApiKey;
        }
    }
    private void ResetKeyButton_OnClick(object? sender, RoutedEventArgs e)
    {
        // Clear the saved API key
        var settings = _settingsService.Load();
        settings.ApiKey = null;
        _settingsService.Save(settings);

        // Open a fresh API key window using the same services
        var apiWindow = new ApiKeyWindow(_settingsService, _apiService);
        apiWindow.Show();

        // Close current main window
        Close();
    }

    private async void SaveButton_OnClick(object? sender, RoutedEventArgs e)
    {
        var key = ApiKeyTextBox.Text?.Trim();

        if (string.IsNullOrEmpty(key))
        {
            StatusText.Text = "Please enter an API key.";
            return;
        }

        StatusText.Text = "Validating key...";
        SaveButton.IsEnabled = false;

        var isValid = await _apiService.ValidateKeyAsync(key);

        if (!isValid)
        {
            StatusText.Text = "API key is invalid or could not be validated. Please check and try again.";
            SaveButton.IsEnabled = true;
            return;
        }

        var settings = _settingsService.Load();
        settings.ApiKey = key;
        _settingsService.Save(settings);

        var mainWindow = new MainWindow(_settingsService, _apiService);
        mainWindow.Show();

        Close();
    }
}
